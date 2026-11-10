# Changelog

## 1.x

- First release: calendars (calendarList), events read (events.list each occurrence or the series,
  events.get) and written (insert, update on its etag, delete), free/busy (freebusy.query), over
  OAuth 2.0 with the token refreshed before it expires and once on a 401; Google's quotas and terms
  as `policy.json` (10,000 a minute per project, 600 a minute per user, 1,000,000 a day), a 403 rate
  limit read as a usage limit.
