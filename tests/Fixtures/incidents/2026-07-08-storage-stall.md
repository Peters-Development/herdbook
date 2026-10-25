# Incident 2026-07-08 — Uploads failed after a storage stall

**Status:** root cause confirmed.

## Timeline

- 09:12 — `app-1` loses its NFS mount on `storage-1`.
- 09:52 — The mount is back; uploads work again.

## Root cause

A fictional NFS stall on `storage-1`.
