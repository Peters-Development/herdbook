# Incident 2026-07-27 — App stack did not come back after a reboot

**Impact:** Every site on `app-1` returned `Bad Gateway`.
About 40 minutes, mostly unnoticed.

**Server:** `app-1`.

---

## Summary

`app-1` was rebooted by its provider and the containers did not start again.

## Timeline

- 03:10 — `app-1` reboots.
- 03:50 — The containers are started by hand; `example.com` is back.
