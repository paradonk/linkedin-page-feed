=== LinkedIn Page Feed ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later

Show the latest posts from a LinkedIn Company Page.

== Setup ==

1. Upload the `linkedin-page-feed` folder to `wp-content/plugins/` and activate it.
2. Go to Settings → LinkedIn Feed and copy the redirect URL shown there into your
   LinkedIn app's Auth tab (Authorized redirect URLs).
3. Enter the app's Client ID and Client secret, plus a default Page ID, and save.
   Optionally define `LPF_CLIENT_SECRET` in wp-config.php instead of saving the secret.
4. Click "Connect LinkedIn" and sign in with an account that administers the Page.
   The app needs the r_organization_social and rw_organization_admin scopes.

== Usage ==

[linkedin_feed]
[linkedin_feed org="1234567" count="24" cols="3"]

* org   – Page (organization) ID; defaults to the one in settings
* count – number of posts, 1–100 (default 24)
* cols  – grid columns, 1–6 (default 3; collapses to 2 / 1 on smaller screens)

Posts are cached (default 60 minutes). If LinkedIn is unreachable, the last
fetched posts are shown. Errors are only visible to administrators.

The access token is refreshed automatically while the refresh token is valid
(about one year); after that, click Reconnect.
