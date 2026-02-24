#!/usr/bin/env node

const REQUIRED = [22, 12, 0]

function parseVersion(version) {
  const [major = '0', minor = '0', patch = '0'] = version.split('.')
  return [Number(major), Number(minor), Number(patch)]
}

function isAtLeast(current, required) {
  for (let i = 0; i < required.length; i += 1) {
    const cur = current[i] ?? 0
    const req = required[i] ?? 0
    if (cur > req) return true
    if (cur < req) return false
  }
  return true
}

const currentRaw = process.versions.node
const current = parseVersion(currentRaw)

if (!isAtLeast(current, REQUIRED)) {
  const requiredRaw = REQUIRED.join('.')
  console.error(
    `Node ${requiredRaw}+ is required (current: ${currentRaw}). Run: fnm use 22.12.0`
  )
  process.exit(1)
}
