#!/usr/bin/env python3
"""UserPromptSubmit hook that injects the slackbot-v2 Opus reply reminder
as additionalContext on every turn.

Slackbot v2 is a stateless pipe: plain assistant text never reaches Slack.
Opus-class models tend to answer with plain text anyway, so a per-turn
reminder nudges them to call a terminal mcp__slackbot__* tool instead.

Delivering the reminder via a UserPromptSubmit hook (rather than
concatenating a <system-reminder>-wrapped suffix into the user turn text)
lets the CLI's normalizeMessagesForAPI / shouldUseMidConvSystem promote it
to a real {role:"system"} turn on models that accept mid-conversation
system messages (opus-4-8, mythos, fable, …) and fall back to the
<system-reminder> wrap on those that don't. The CLI owns that model gate;
this hook does not re-implement it.

Installed for slackbot v2 sessions: env-manager registers it in
launcher-settings.json only when CCR_OPUS_REMINDER_HOOK_BODY is set, and
CCR sets that env var only when the session is Slack-originated and the
ccr_slackbot_v2_system_turn_reminder GrowthBook boolean is on. Registered
as a UserPromptSubmit hook so it fires on every user turn (seed included).

The Opus-class gate lives here (is_opus on the transcript's most recent
main-loop assistant model), same as stop-hook-reply-gate.py: the
configured model can fall back CLI-side per request, so the effective
model is only knowable at runtime from the transcript. A non-Opus or
indeterminate model emits nothing — the hook is a no-op.
"""

import json
import os
import sys


def is_opus(model):
    return "opus" in (model or "").lower()


def main():
    body = os.environ.get("CCR_OPUS_REMINDER_HOOK_BODY", "")
    if not body:
        # Not installed for this session (env var is the enable signal).
        sys.exit(0)

    raw = sys.stdin.read()
    try:
        payload = json.loads(raw) if raw.strip() else {}
    except json.JSONDecodeError:
        sys.exit(0)

    transcript_path = payload.get("transcript_path") or ""
    last_model = None
    try:
        with open(transcript_path, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    entry = json.loads(line)
                except json.JSONDecodeError:
                    continue
                if entry.get("type") != "assistant" or entry.get("isSidechain"):
                    continue
                model = (entry.get("message") or {}).get("model")
                if model == "<synthetic>":
                    continue
                last_model = model
    except OSError:
        pass

    # On the seed turn there is no assistant entry yet, so last_model is
    # None. The reminder is wanted on the seed turn too (that's what the
    # legacy in-turn <system-reminder> suffix covered), so emit when the
    # model is indeterminate — the CLI's own shouldUseMidConvSystem gate
    # decides whether the attachment becomes role:"system" or a wrapped
    # user-turn block, and neither outcome is wrong for a non-Opus model
    # that happened to be serving (the reminder is just unnecessary).
    if last_model is not None and not is_opus(last_model):
        sys.exit(0)

    json.dump(
        {
            "hookSpecificOutput": {
                "hookEventName": "UserPromptSubmit",
                "additionalContext": body,
            }
        },
        sys.stdout,
    )
    sys.exit(0)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        # Never block the turn on a hook failure.
        sys.exit(0)
