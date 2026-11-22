# LifeOS landing page

Open `http://localhost/trello_clone/landing_page/` or `lavender.php` for the selected Lavender design. The comparison index and other designs have been removed. Apache opens `lavender.php` directly using the included DirectoryIndex setting.

The one-page experience includes keyboard-accessible product-tour tabs, responsive layouts, FAQs, and the request-access form. All assets are local, and product previews are illustrative.

## Request handling

The form actually saves requests with PHP 7.0+, independently of the LifeOS database or installer. JavaScript fetches a CSRF token, then submits to `request.php`. Name, email, intended use, group size, and consent are collected; timezone and a message are optional. It does not collect passwords or payment details. Spam protection includes a hidden honeypot and a five-requests-per-hour rate limit per hashed network identifier. Visitors on a shared network share this limit.

Requests are appended as one JSON object per line to `private/requests.jsonl`. **No email notification is sent.** Review/download that file using FTP or your hosting file manager; contact the applicant yourself and provision their account manually. `status: new` is a stored label, not an automated workflow. Keep applicant files out of Git and public directories; the included `.htaccess` denies web access to the private folder. Local Git ignore rules exclude all request and rate-limit files.

The `private` folder must be writable by PHP (use normal hosting ownership/permissions, not world-writable permissions). A storage failure produces an honest error rather than claiming a request was accepted. Remove requests when no longer needed; the script does not automatically delete applicant data. This is not integrated with the app’s invitation/account-creation APIs.

## Publish using FTP

1. Upload the whole `landing_page` folder, including its hidden `.htaccess` files and the protected `private` folder, to Apache/shared hosting. PHP 7.0+ and sessions are required. No database, Composer, build step, CDN, or installer is needed.
2. No index file is required: the included `.htaccess` sets `DirectoryIndex lavender.php`. You can deploy the folder’s contents to a standalone domain root as well. Delete old index.php, paper.php, mono.php, and page.php files if you uploaded the earlier options.
3. Before launch, submit a real test request, confirm it appears in the private file, and verify that requesting `/private/requests.jsonl` over HTTP returns 403. Apache must honor `.htaccess`; configure equivalent denial rules yourself on a different server. The PHP development server does not enforce `.htaccess`.
4. Review the privacy notice for your real operating practices and add your business/contact details when established. Pricing and availability are intentionally confirmed manually before activation.

Existing LifeOS application files and database are not changed by this folder. A future landing-page update should preserve `private/requests.jsonl` and any live rate-limit files.

## Validation

Run `node tests/landing.cjs` from the project root. It starts an isolated PHP server with a temporary storage directory, exercises the Lavender page and the submission flow, then deletes only its test data. It never writes test requests into the live landing-page storage.

Design research: Trello’s product-led board previews, Asana’s direct signup calls to action, and Notion’s simple feature narrative informed the structure. The copy, UI examples, styles, and LifeOS positioning are original.
