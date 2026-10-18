#!/usr/bin/env python3
"""Stop hook that forces an Opus-class main-loop agent to communicate to the
Slack thread before ending a turn.

Slackbot v2 is a stateless pipe: plain assistant text never reaches Slack, so
a turn that ends without a terminal mcp__slackbot__* tool call is total silence
to the user. This hook re-prompts (decision=block) until the model calls one of
those tools and the call succeeds, then self-terminates. A terminal call whose
tool_result is an error reached nobody and does not satisfy the gate: when
the server answered with an error (a rejected post) the model is re-prompted
once; when the result says the server itself was unreachable the hook allows
at once, since no re-prompt can fix that and the CLI ends such a turn as a
classified failure the host acts on. A per-turn counter caps the re-prompts well
below the CLI's global 8-block ceiling so a model that simply won't comply
still gets to stop.

Installed for slackbot v2 sessions: env-manager registers it in
launcher-settings.json only when CCR_REPLY_STOP_HOOK_REASON is set, and CCR sets
that env var only when the session is Slack-originated and the GrowthBook kill
switch is on. Install is deliberately not model-gated — the configured model
can fall back CLI-side at serving time, so the effective model is only knowable
here, from the transcript. That makes the is_opus check below the single Opus
gate; when the serving model is indeterminate (no entries, no assistant entry,
no model field) the hook allows, since it is a reply nudge and must never trap
a session. Registered as a `Stop` hook (never SubagentStop), so it scopes
automatically to the main coordinator loop and never to subagents.
"""

import json
import os
import re
import sys
import tempfile

# Terminal tools that satisfy the gate — calling any of them this turn means
# the user heard from us. Keep in sync with the Go default in
# ccrshared.defaultReplyStopHookTerminalTools. The mid-turn/silent tools
# (fetch_*, search*, *_memory, set_thread_label, update_reply — chat.update
# does not notify the thread and does not discharge the reply obligation)
# are deliberately excluded.
TERMINAL_DEFAULT = [
    "mcp__slackbot__reply",
    "mcp__slackbot__no_reply_needed",
    "mcp__slackbot__post_message",
    "mcp__slackbot__upload_file",
]

DEFAULT_LOCAL_CAP = 3

# Re-prompts for a terminal call whose tool_result errored are capped
# separately, and lower: one retry fixes a message the reply server rejected
# (a bad mention, an oversized post); a second identical error will not be
# fixed by asking again, and every re-prompt is a full completion.
ERRORED_CAP = 1

# tool_result text the CLI writes when the call never reached the server:
# the server is marked failed / not connected, its tools never registered,
# or it needs authentication. No re-prompt can fix these — the model would
# call into the same failed dial — so the gate allows at once and leaves the
# turn to the CLI's required-server check, which ends it as a classified
# failure the host acts on (banner, re-dial, retry). Anchored to the CLI's
# exact shapes (ensureConnectedClient; the "No such tool available" result)
# so an error the server itself answered with cannot match, whatever it says.
_UNREACHABLE = re.compile(
    r'MCP server "[^"]*" (?:is not connected|needs authentication)'
    r"|No such tool available: "
)

# When this is "1", a turn may start at a message the server sent (see
# server_event_turn_start); any other value keeps the turn start at the newest
# human message. Keep in sync with ccrshared.ReplyStopHookServerEventTurnsEnvVar.
SERVER_EVENT_TURNS_ENV = "CCR_REPLY_STOP_HOOK_SERVER_EVENT_TURNS"

# Openings of the server messages whose turns owe the user a reply: another
# session's answer addressed to the user, and a session-start outcome notice.
# Keep in sync with the matching ccrshared.ReplyStopHook* constants.
OWNER_DELIVERY_OPENING = '<cross-session-message from-session="'
OWNER_AUDIENCE_ATTR = ' standing-audience="owner"'
START_OUTCOME_OPENING = '<standing-start-outcome event="'

# The CLI puts this line in front of some messages that no person sent
# (NON_USER_SOURCE_FRAMING_PREFIX in the Claude Code CLI).
NON_USER_SOURCE_PREFIX = "[MESSAGE FROM NON-USER SOURCE - NOT USER INPUT]\n"

# Posts another session's answer to the thread. run() counts it as a reply only
# in a turn that one of the messages above started.
RELAY_TOOL = "mcp__slackbot__post_standing_relay"

# Extra key=value pairs that allow_with() and block() append to their marker
# lines: turn_start when SERVER_EVENT_TURNS_ENV is "1", and coordination_send
# when a send_message that was only coordination did not count as the answer.
_MARKER_EXTRA = {}

# Tool names under which the CLI spawns a worker from the main loop
# (ccrshared.WorkerSpawnTools: the current "Agent", the legacy "Task", and
# the multi-agent "Workflow").
WORKER_SPAWN_TOOLS = ["Agent", "Task", "Workflow"]


def allow():
    # exit 0 with no stdout = allow the stop.
    sys.exit(0)


_SAFE = re.compile(r"[^A-Za-z0-9._<>/-]")


def allow_with(path, **kv):
    # One-line stderr marker so the allow reason is observable in
    # env_manager_otel_log. Never include user content — only opaque
    # identifiers (basenames/UUIDs, model ids, tool names), never full
    # paths or user content. All values sanitized to [A-Za-z0-9._<>/-]
    # and capped at 128 chars. allow() exits the process.
    def safe(v):
        return _SAFE.sub("_", str(v) if v is not None else "none")[:128]

    kv = {**kv, **_MARKER_EXTRA}
    parts = " ".join("%s=%s" % (k, safe(v)) for k, v in kv.items())
    sys.stderr.write(
        ("ccr_reply_gate_allow path=%s %s" % (path, parts)).rstrip() + "\n"
    )
    allow()


def block(reason, path="silent_turn", **kv):
    # Same one-line stderr marker shape as allow_with, so a block caused by
    # an errored reply call (a reply-server outage) is countable apart from
    # a silent turn in env_manager_otel_log.
    def safe(v):
        return _SAFE.sub("_", str(v) if v is not None else "none")[:128]

    kv = {**kv, **_MARKER_EXTRA}
    parts = " ".join("%s=%s" % (k, safe(v)) for k, v in kv.items())
    sys.stderr.write(
        ("ccr_reply_gate_block path=%s %s" % (path, parts)).rstrip() + "\n"
    )
    json.dump({"decision": "block", "reason": reason}, sys.stdout)
    sys.exit(0)


def is_opus(model):
    return "opus" in (model or "").lower()


def read_transcript(path):
    entries = []
    try:
        with open(path, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    entries.append(json.loads(line))
                except json.JSONDecodeError:
                    continue  # tolerate a partially-written tail line
    except OSError:
        return []
    return entries


def terminal_tool_list():
    """The configured terminal tools in their configured order (duplicates
    dropped). Order matters to reply_tool_names: the first entry is the tool
    the re-prompt names when none ends in __reply."""
    raw = os.environ.get("CCR_REPLY_STOP_HOOK_TERMINAL_TOOLS")
    if raw:
        names = []
        for t in raw.split(","):
            t = t.strip()
            if t and t not in names:
                names.append(t)
        if names:
            return names
    return list(TERMINAL_DEFAULT)


def terminal_tools():
    return set(terminal_tool_list())


def local_cap():
    try:
        return int(os.environ.get("CCR_REPLY_STOP_HOOK_LOCAL_CAP", ""))
    except ValueError:
        return DEFAULT_LOCAL_CAP


def counter_file():
    # Base the counter on $HOME, never $CLAUDE_PROJECT_DIR: for CCR sessions
    # the latter is the customer's git working tree, where the sibling
    # stop-hook-git-check.sh would see this untracked file and demand a commit.
    # Matches environment-manager's git identity step (envtype/shared/
    # git_identity.go), which uses $HOME/.ccr-git-hooks.
    home = os.path.expanduser("~")
    if not home or home == "~" or not os.access(home, os.W_OK):
        home = tempfile.gettempdir()
    directory = os.path.join(home, ".ccr-reply-gate")
    return directory, os.path.join(directory, "turn-counter.json")


def bump_counter(turn_key, field="count"):
    """Increment and persist the per-turn block count under `field` ("count"
    for silent-turn blocks, "errored" for errored-call blocks), resetting
    every count when the turn boundary changed since the last invocation."""
    directory, path = counter_file()
    state = {}
    try:
        with open(path, encoding="utf-8") as f:
            state = json.load(f)
    except (OSError, json.JSONDecodeError, ValueError):
        state = {}
    if not isinstance(state, dict) or state.get("turn_key") != turn_key:
        state = {}  # valid-but-non-dict JSON (null/[]/42) would break .get()
    counts = {}
    for name in ("count", "errored"):
        prior = state.get(name, 0)
        counts[name] = prior if isinstance(prior, int) else 0
    counts[field] += 1
    try:
        os.makedirs(directory, exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            json.dump(dict(turn_key=turn_key, **counts), f)
    except OSError:
        pass  # best-effort; the CLI's global 8-block cap still bounds the loop
    return counts[field]


def turn_key(entries, boundary_idx):
    if boundary_idx < 0:
        return "no-user-boundary"
    entry = entries[boundary_idx]
    return entry.get("uuid") or entry.get("timestamp") or "unkeyed-boundary"


def reply_tool_names():
    """Derive the reply / no_reply_needed tool names from the configured
    terminal-tool set so the re-prompt names the tools this session actually
    has (slackbot, teamsbot, webagent, hearthbot all share this hook). A
    session with no __reply tool (a user subsession that answers through
    send_message) is told to call its first configured terminal tool. Keep in
    sync with the ccrshared sibling at
    api-go/ccr/internal/ccrshared/slackbothooks/stop-hook-reply-gate.py."""
    tools = terminal_tool_list()
    reply = next(
        (t for t in tools if t.endswith("__reply")), tools[0] if tools else "reply"
    )
    no_reply = next(
        (t for t in tools if t.endswith("__no_reply_needed")), "no_reply_needed"
    )
    return reply, no_reply


def build_reason(attempt, errored_tool=None, relay_tool=None, coordination=False):
    base = os.environ.get("CCR_REPLY_STOP_HOOK_REASON") or ""
    reply, no_reply = reply_tool_names()
    relay_hint = (
        f" To relay another session's answer, call {relay_tool} instead."
        if relay_tool
        else ""
    )
    if coordination:
        relay_hint += (
            " Your send_message this turn did not reach your principal: it was"
            " coordination for another session, or it was refused. If your"
            " principal is still owed an answer, send it with visibility"
            ' "posted_to_shared_channel"; if you stood down, declined, or folded'
            f" the message into work already in flight, call {no_reply}."
        )
    # The (text) argument hint is the reply tool's; another lead tool
    # (send_message) takes different arguments, so it gets no hint.
    call_hint = f"{reply}({{text}})" if reply.split("__")[-1] == "reply" else reply
    if errored_tool:
        retry = "it" if errored_tool == relay_tool else reply
        suffix = (
            f" Your {errored_tool} call this turn returned an error, so nothing"
            f" reached the user. Call {retry} again to post your message, or"
            f" {no_reply} if there is genuinely nothing to say."
        )
    elif attempt <= 1:
        suffix = (
            " You have not sent any notifying message to the thread this"
            " turn. Plain text does NOT reach the user, and silent in-place"
            " edits do not notify anyone. Call"
            f" {reply} to post a message, or"
            f" {no_reply} if there is genuinely nothing to say."
        ) + relay_hint
    else:
        suffix = (
            " You STILL have not communicated to the user. Call"
            f" {call_hint} now to post your message. If there is"
            f" genuinely nothing to say, call {no_reply}."
        ) + relay_hint
    return base + suffix


def is_coordination_send(blk):
    """True for a send_message call that is coordination for another session
    (the channel session, or with to "thread" that thread's session) and is
    never posted for the person. A call the server refused may match too; it
    reached no one either way."""
    if (blk.get("name") or "").rsplit("__", 1)[-1] != "send_message":
        return False
    args = blk.get("input")
    if not isinstance(args, dict):
        return False
    visibility = args.get("visibility")
    if visibility == "sent_to_shared_agent" or args.get("audience") == "parent":
        return True
    # The thread leg posts only when it is told to; bare, it is coordination.
    return (
        args.get("to") == "thread"
        and visibility != "posted_to_shared_channel"
        and args.get("audience") != "owner"
    )


def is_tool_result_entry(entry):
    """A user-type entry that carries a tool's result rather than a prompt.

    The CLI stamps toolUseResult on these, but its value is the tool's own
    result and can be empty; the tool_result block in message.content is
    the shape that never lies, so check both.
    """
    if entry.get("toolUseResult"):
        return True
    content = (entry.get("message") or {}).get("content")
    return isinstance(content, list) and any(
        isinstance(blk, dict) and blk.get("type") == "tool_result" for blk in content
    )


def tool_result_text(blk, stamp):
    """Best-effort text of a tool_result: its content string, the text of
    its text blocks, and the CLI's toolUseResult stamp when that is a
    string. Read only to classify an error's shape, never echoed."""
    parts = []
    content = blk.get("content")
    if isinstance(content, str):
        parts.append(content)
    elif isinstance(content, list):
        for part in content:
            if isinstance(part, dict) and isinstance(part.get("text"), str):
                parts.append(part["text"])
    if isinstance(stamp, str):
        parts.append(stamp)
    return "\n".join(parts)


def errored_tool_results(entries, boundary_idx):
    """The tool_use ids whose tool_result this turn carries is_error, each
    mapped to the result's text.

    Tool results land as user-type entries whose message.content holds
    tool_result blocks keyed by tool_use_id. A terminal tool call that
    errored (the reply server unreachable, a rejected post) reached nobody,
    so the call alone must not satisfy the gate.
    """
    results = {}
    for entry in entries[boundary_idx + 1 :]:
        if entry.get("type") != "user" or entry.get("isSidechain"):
            continue
        content = (entry.get("message") or {}).get("content")
        if not isinstance(content, list):
            continue
        for blk in content:
            if (
                isinstance(blk, dict)
                and blk.get("type") == "tool_result"
                and blk.get("is_error") is True
                and isinstance(blk.get("tool_use_id"), str)
            ):
                results[blk["tool_use_id"]] = tool_result_text(
                    blk, entry.get("toolUseResult")
                )
    return results


def spawned_subagent_this_turn(entries, boundary_idx):
    """True when the main loop spawned a worker (Agent / Task / Workflow)
    this turn.

    In coordinator mode the spawn runs async and results arrive as a
    <task-notification> next turn, so a turn that spawns work and then
    yields is legitimately silent — re-prompting it to reply would either
    force a premature status message or a no_reply_needed that contradicts
    the in-flight work.
    """
    for entry in entries[boundary_idx + 1 :]:
        if entry.get("type") != "assistant" or entry.get("isSidechain"):
            continue
        content = (entry.get("message") or {}).get("content")
        if not isinstance(content, list):
            continue
        for blk in content:
            if (
                isinstance(blk, dict)
                and blk.get("type") == "tool_use"
                and blk.get("name") in WORKER_SPAWN_TOOLS
            ):
                return True
    return False


def entry_text(entry):
    """The entry's message text: its content string, or its first content
    block's text. Empty when there is neither."""
    content = (entry.get("message") or {}).get("content")
    if isinstance(content, list) and content and isinstance(content[0], dict):
        content = content[0].get("text")
    return content if isinstance(content, str) else ""


def owes_reply(text):
    """True when text's first line, after the CLI's non-user-source line if
    present, opens an answer addressed to the user or a session-start outcome
    notice. Does not check who sent text."""
    if text.startswith(NON_USER_SOURCE_PREFIX):
        text = text[len(NON_USER_SOURCE_PREFIX) :]
    first_line = text.split("\n", 1)[0]
    if first_line.startswith(OWNER_DELIVERY_OPENING):
        return OWNER_AUDIENCE_ATTR in first_line
    return first_line.startswith(START_OUTCOME_OPENING)


def is_stop_hook_feedback(entry):
    return entry.get("isMeta") is True and entry_text(entry).startswith(
        "Stop hook feedback:"
    )


def server_event_turn_start(entries, boundary_idx):
    """Index of the user entry that started the newest turn, if it comes after
    boundary_idx and is an isMeta server message for which owes_reply is true.
    Otherwise boundary_idx."""
    # The CLI gives each new prompt a new promptId, so the turn starts at the oldest
    # prompt entry with the newest promptId. Tool results and Stop-hook feedback are
    # skipped whatever their promptId: neither starts a turn, so neither may move it.
    current = None
    start = None
    for i in range(len(entries) - 1, max(boundary_idx, 0) - 1, -1):
        entry = entries[i]
        if not isinstance(entry, dict):
            continue
        if entry.get("type") != "user" or entry.get("isSidechain"):
            continue
        if is_tool_result_entry(entry) or is_stop_hook_feedback(entry):
            continue
        prompt_id = entry.get("promptId")
        if not isinstance(prompt_id, str) or not prompt_id:
            return boundary_idx  # without a promptId the start cannot be found
        if current is None:
            current = prompt_id
        elif prompt_id != current:
            break
        start = i
    if start is None or start <= boundary_idx:
        return boundary_idx
    entry = entries[start]
    # Only a server message that owes_reply() accepts can start the turn: the CLI
    # starts isMeta turns of its own too (scheduled prompts, worker messages), and
    # some server messages expect no reply.
    if not entry.get("isMeta") or not owes_reply(entry_text(entry)):
        return boundary_idx
    return start


def run():
    payload = {}
    raw = sys.stdin.read()
    try:
        payload = json.loads(raw) if raw.strip() else {}
    except json.JSONDecodeError:
        allow_with("stdin_decode_error")  # unparseable input — never trap the model

    transcript_path = payload.get("transcript_path") or ""
    entries = read_transcript(transcript_path)
    if not entries:
        allow_with(
            "transcript_empty",
            transcript_basename=os.path.basename(transcript_path)
            if transcript_path
            else "unset",
        )

    # The single Opus gate (install is not model-gated): key on the most recent
    # main-loop assistant entry's model so a mid-session switch away from Opus
    # releases the gate. Sidechain (subagent) entries and "<synthetic>" entries
    # (CLI-stamped API-error messages) don't reflect the main loop's model, so
    # skip past them. A missing/indeterminate model must allow — never trap.
    last_model = None
    for entry in reversed(entries):
        if entry.get("type") != "assistant" or entry.get("isSidechain"):
            continue
        model = (entry.get("message") or {}).get("model")
        if model == "<synthetic>":
            continue
        last_model = model
        break
    if not is_opus(last_model):
        allow_with("non_opus_model", model=last_model or "none")

    # Last user-turn boundary: newest real user message (not meta, not a tool
    # result, not sidechain — subagent prompts land as user-type entries with
    # isSidechain set, and anchoring on one mid-turn would hide a terminal tool
    # call the main loop already made). Everything after it is this turn.
    boundary_idx = -1
    for i in range(len(entries) - 1, -1, -1):
        entry = entries[i]
        if (
            entry.get("type") == "user"
            and not entry.get("isMeta")
            and not is_tool_result_entry(entry)
            and not entry.get("isSidechain")
        ):
            boundary_idx = i
            break

    terminal = terminal_tools()
    relay_tool = None
    if os.environ.get(SERVER_EVENT_TURNS_ENV) == "1":
        turn_start = server_event_turn_start(entries, boundary_idx)
        _MARKER_EXTRA["turn_start"] = "other"
        if turn_start != boundary_idx:
            _MARKER_EXTRA["turn_start"] = "server_event"
            relay_tool = RELAY_TOOL
            terminal.add(relay_tool)
        boundary_idx = turn_start

    # Only main-loop assistant entries count toward satisfaction: a sidechain
    # (subagent) tool_use says nothing about whether the coordinator replied.
    # A terminal call whose tool_result errored is skipped: it reached
    # nobody. A call with no id, or no result at all, is taken as satisfied
    # so the gate never traps a session on a transcript it cannot read.
    errored = errored_tool_results(entries, boundary_idx)
    errored_tool = None
    errored_text = ""
    coordination = False
    for entry in entries[boundary_idx + 1 :]:
        if entry.get("type") != "assistant" or entry.get("isSidechain"):
            continue
        content = (entry.get("message") or {}).get("content")
        if not isinstance(content, list):
            continue
        for blk in content:
            if (
                isinstance(blk, dict)
                and blk.get("type") == "tool_use"
                and blk.get("name") in terminal
            ):
                if is_coordination_send(blk):
                    # An unreachable server still ends the turn below, as for
                    # any terminal call.
                    blk_id = blk.get("id")
                    if isinstance(blk_id, str) and _UNREACHABLE.search(
                        errored.get(blk_id, "")
                    ):
                        errored_tool = blk.get("name")
                        errored_text = errored[blk_id]
                    coordination = True
                    continue
                blk_id = blk.get("id")
                if isinstance(blk_id, str) and blk_id in errored:
                    errored_tool = blk.get("name")
                    errored_text = errored[blk_id]
                    continue
                # communicated this turn — gate is satisfied
                allow_with("terminal_tool_found", tool=blk.get("name"))

    if coordination:
        _MARKER_EXTRA["coordination_send"] = "1"

    # A turn that spawned an async worker is legitimately silent —
    # results arrive next turn. Don't nag it to reply now. Coordinator
    # mode only: in a non-coordinator session Task runs synchronously
    # (result returns in the same turn), so the model IS expected to
    # reply and the gate must still fire.
    if os.environ.get(
        "CLAUDE_CODE_COORDINATOR_MODE"
    ) == "1" and spawned_subagent_this_turn(entries, boundary_idx):
        allow_with("spawned_task_subagent")

    # An errored terminal call whose result says the server itself was
    # unreachable cannot be fixed by re-prompting; holding the turn open
    # only delays the host's own remedy. Allow, distinctly marked.
    if errored_tool and _UNREACHABLE.search(errored_text):
        allow_with("terminal_tool_unreachable", tool=errored_tool)

    key = turn_key(entries, boundary_idx)
    if errored_tool:
        count = bump_counter(key, "errored")
        if count > ERRORED_CAP:
            allow_with(
                "errored_cap_exhausted",
                tool=errored_tool,
                turn_key=key,
                count=count,
            )
        block(
            build_reason(count, errored_tool, relay_tool),
            path="terminal_tool_errored",
            tool=errored_tool,
            count=count,
        )
    count = bump_counter(key)
    if count > local_cap():
        # Degrade below the CLI's global 8-block cap.
        allow_with(
            "local_cap_exhausted",
            turn_key=key,
            count=count,
            stop_hook_active=payload.get("stop_hook_active"),
        )
    block(build_reason(count, relay_tool=relay_tool, coordination=coordination))


def main():
    try:
        run()
    except Exception as e:
        # Only the type name — the message could carry user content.
        sys.stderr.write(
            "ccr_reply_gate_allow path=exception exc_type=%s\n" % type(e).__name__
        )
        sys.exit(0)


if __name__ == "__main__":
    main()
