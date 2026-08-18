<?php
/**
 * LegalController — the privacy policy, served as a public page.
 *
 * Both stores require a reachable privacy-policy URL on the listing, and it
 * has to be a real web page rather than a document in a repository. The API
 * is already a public HTTPS host, so it serves one:
 *
 *     https://fineprint-backend-gsgu.onrender.com/privacy
 *
 * It is written from what the code ACTUALLY does. A policy that describes an
 * app you meant to build is worse than none: it is a public, dated statement
 * that does not match your own database, and it is exactly what a store
 * review or a regulator compares against.
 *
 * If you change what is collected, change this the same day.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Request;
use App\Response;

final class LegalController
{
    /** Update when the substance changes, not on every deploy. */
    private const LAST_UPDATED = '18 August 2026';

    private const CONTACT = 'jivoit0@gmail.com';

    public function privacy(Request $request): void
    {
        $updated = self::LAST_UPDATED;
        $contact = self::CONTACT;

        Response::html(<<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy Policy — FinePrint</title>
<style>
  :root { color-scheme: light dark; }
  body {
    font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    max-width: 44rem; margin: 0 auto; padding: 2rem 1.25rem 4rem;
    color: #1a1d21; background: #fff;
  }
  @media (prefers-color-scheme: dark) {
    body { color: #e8eaed; background: #16181c; }
    td, th { border-color: #2c3036 !important; }
    code { background: #23262b !important; }
  }
  h1 { font-size: 1.75rem; margin-bottom: .25rem; }
  h2 { font-size: 1.15rem; margin-top: 2.25rem; }
  .updated { color: #6b7280; font-size: .9rem; margin-top: 0; }
  table { border-collapse: collapse; width: 100%; margin: 1rem 0; font-size: .95rem; }
  th, td { text-align: left; padding: .5rem .6rem; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  th { font-weight: 600; }
  code { background: #f3f4f6; padding: .1rem .3rem; border-radius: 3px; font-size: .9em; }
  .lead { font-size: 1.05rem; }
</style>
</head>
<body>

<h1>Privacy Policy</h1>
<p class="updated">FinePrint · Last updated {$updated}</p>

<p class="lead">FinePrint shows you article summaries from public blog feeds.
This policy describes exactly what the app stores, why, and how long for. It
is written from what the software actually does.</p>

<h2>What we collect</h2>

<table>
  <tr><th>Data</th><th>Why</th><th>Kept for</th></tr>
  <tr><td>Email address</td><td>To identify your account and let you sign in</td><td>Until you delete your account</td></tr>
  <tr><td>Password</td><td>Stored only as a bcrypt hash. We cannot read it</td><td>Until you delete your account</td></tr>
  <tr><td>Display name (optional)</td><td>So the app can greet you</td><td>Until you delete or clear it</td></tr>
  <tr><td>Chosen topics</td><td>To decide which articles your feed contains</td><td>Until you delete your account</td></tr>
  <tr><td>Which articles were shown to you, which you opened, and how long you spent reading</td><td>To order your feed and stop repeating articles you have already seen</td><td>90 days</td></tr>
  <tr><td>Blogs you chose to see less of</td><td>To honour that choice</td><td>Until you undo it</td></tr>
  <tr><td>Sign-in tokens and basic device information</td><td>To keep you signed in and let you sign out other devices</td><td>Until the token expires or you sign out</td></tr>
  <tr><td>IP address</td><td>Rate limiting only, to stop automated abuse of sign-in</td><td>1 hour</td></tr>
  <tr><td>Donation records</td><td>Accounting and tax obligations</td><td>Retained after account deletion, with your account detached</td></tr>
</table>

<h2>What we do not do</h2>
<ul>
  <li>We do not sell or rent your data. There is nobody to sell it to.</li>
  <li>We do not use advertising networks, and there are no ads in the app.</li>
  <li>We do not use third-party analytics or tracking SDKs. The reading data
      described above goes to our own server and nowhere else.</li>
  <li>We do not track you across other apps or websites.</li>
  <li>We do not collect your location, contacts, photos, or any device
      identifier for advertising.</li>
  <li>We never see your card details. Payments are handled by Instamojo, who
      have their own privacy policy.</li>
</ul>

<h2>Reading data, specifically</h2>
<p>Because it is the least obvious thing here, it is worth stating plainly.
When an article card is visible on your screen for about a second, the app
records that it was shown. When you open one, it records that you opened it
and roughly how long before you came back.</p>
<p>That is used for one purpose: ordering your feed so it contains more of
what you actually read and less of what you skip. It is tied to your account,
it is never shared, and it is deleted after 90 days. The app cannot see
anything you do on the publisher's website — articles open in your device's
own browser, which we have no visibility into.</p>

<h2>Article content</h2>
<p>Articles come from publicly available RSS and Atom feeds. We store a short
excerpt, a headline, and a link. Every article links to the publisher's
original page, and publishers keep ownership of their work. If you publish a
blog and want it removed, email us and we will remove it.</p>

<h2>Your choices</h2>
<ul>
  <li><strong>Delete your account</strong> — Profile → Delete account, in the
      app. This removes your email, name, topics, reading history and sign-in
      tokens immediately and permanently. It cannot be undone.</li>
  <li><strong>Change your topics</strong> — Profile → Your topics.</li>
  <li><strong>Mute a blog</strong> — long-press any article, then
      "Show fewer from…". Reversible under Profile → Hidden blogs.</li>
  <li><strong>Get a copy of your data</strong> — email us and we will send it.</li>
</ul>

<h2>Where your data is held</h2>
<p>On servers in the United States, operated by Render (application) and Neon
(database). Connections use TLS.</p>

<h2>Children</h2>
<p>FinePrint is not directed at children under 13, and we do not knowingly
collect data from them.</p>

<h2>Changes</h2>
<p>If this policy changes materially we will update the date above. Continuing
to use the app after a change means you accept it.</p>

<h2>Contact</h2>
<p>Questions, data requests, or a takedown for your blog:
<a href="mailto:{$contact}">{$contact}</a></p>

</body>
</html>
HTML);
    }
}
