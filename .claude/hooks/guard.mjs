// PreToolUse guard (see .claude/settings.json). Reads the tool call as JSON on stdin; exit code 2 blocks it and tells Claude why.
//  1. Files named .env, .env.local, .env.production... hold passwords and keys: never read or written (.env.example is fine).
//  2. `git push` to main or master: changes go through a branch and a pull request.
import { basename } from 'node:path'

let raw = ''
for await (const chunk of process.stdin) raw += chunk
const call = JSON.parse(raw || '{}')
const input = call.tool_input ?? {}

const block = (why) => { console.error(why); process.exit(2) }

export function isSecretFile(path) {
  const name = basename(String(path ?? '').replaceAll('\\', '/'))
  return /^\.env($|\.)/.test(name) && !/^\.env\.(example|sample|dist)$/.test(name)
}

// True for `git push origin main`, `git push origin HEAD:main`, `git push --force origin refs/heads/master`...
export function pushesToMain(command) {
  return String(command ?? '').split(/&&|\|\||;|\n/).some((part) => {
    const tokens = part.trim().split(/\s+/)
    const at = tokens.findIndex((t, i) => t === 'git' && tokens.slice(i + 1).includes('push'))
    if (at < 0) return false
    return tokens.slice(at).some((t) => /^(refs\/heads\/)?(main|master)$/.test(t) || /:(refs\/heads\/)?(main|master)$/.test(t))
  })
}

if (import.meta.url === `file://${process.argv[1]}` || process.argv[1]?.endsWith('guard.mjs')) {
  if (input.file_path && isSecretFile(input.file_path)) block(`Blocked: ${basename(input.file_path)} holds secrets (passwords, keys). Never read or edit it; ask the user, or change .env.example instead.`)
  if (call.tool_name === 'Bash' && pushesToMain(input.command)) block('Blocked: no direct push to main. Push a branch (git push -u origin <branch>) and open a pull request.')
}
