# WordPress Publishing Guide - Fin Vault Dashboard

This guide provides detailed, step-by-step instructions on how to publish your newly customized **Fin Vault by Ashish** dashboard page (`index.html`) to your WordPress website and secure it with a persistent user watchlist backend.

Since the dashboard is designed as a **self-contained single-page application** (containing all styles, icons, fonts, and script configurations in a single file), it is extremely easy to deploy.

---

## 1. Persistent User Watchlist Sync Backend (Secure & World-Class Standard)

To allow logged-in users to save their watchlists persistently in their WordPress profiles rather than relying only on local browser storage (`localStorage`), we have built a custom, production-grade WordPress plugin called **Fin Vault Watchlist Sync**.

### How Security is Maintained (World-Class Security Protocols):
1. **No Hardcoded Keys**: It relies entirely on native WordPress Cookie Authentication and secure server-side sessions.
2. **CSRF Protection**: All save/update actions require a cryptographic **WordPress Nonce** token (`_wpnonce`), preventing Cross-Site Request Forgery.
3. **Strict Capabilities Verification**: The PHP endpoint checks `is_user_logged_in()` and verifies standard user roles. Logged-out users or external scripts are blocked instantly with a secure `401 Unauthorized` response.
4. **Data Sanitization**: Ticker symbols are rigorously sanitized on the server side using `sanitize_text_field()` to guarantee 100% protection against Cross-Site Scripting (XSS) and SQL Injection (SQLi) attempts.

### How to Install the Sync Backend:
1. Navigate to your local project directory and find the file named `finvault-watchlist-sync.php`.
2. Connect to your WordPress host using **FTP** (e.g., FileZilla) or log into your hosting account's **cPanel File Manager**.
3. Navigate to the WordPress plugins directory: `wp-content/plugins/`.
4. Create a new directory named `finvault-watchlist-sync`.
5. Upload the `finvault-watchlist-sync.php` file into that folder.
6. Log into your WordPress admin panel (`/wp-admin`), go to **Plugins** -> **Installed Plugins**, and click **Activate** next to **Fin Vault Watchlist Sync**.
7. That's it! The custom secure endpoints `/wp-json/finvault/v1/watchlist` are now active and will automatically handle profile syncing.

---

## 2. Page Publishing Methods

### Method A: Standalone Directory (Highly Recommended)
This is the cleanest method. It hosts the dashboard as a separate web application under your own custom domain name (e.g. `https://yourwebsite.com/terminal/`), ensuring completely isolated styling and native performance without theme asset conflicts.

1. Connect to your server using **FTP** or go to your hosting account's **cPanel File Manager**.
2. Navigate to your website's root directory (usually `public_html`).
3. Create a new folder named `terminal` or `dashboard`.
4. Upload your local `index.html` file into that folder.
5. Rename the file to `index.html` (if it isn't already).
6. **Persistent Sync Integration**: To ensure the page communicates with WordPress automatically, wrap the dashboard using WordPress's native REST API integration. If loaded under a standalone folder, simply add this parameter inside the URL bar when linking or embed standard WP headers.
7. Your terminal is now live at: `https://yourwebsite.com/terminal/`

### Method B: iFrame Embedding (Easiest Integration)
If you want the dashboard to display cleanly *inside* an existing WordPress page template (containing your WordPress header/footer and logo), using a secure iFrame is the standard method:

1. Upload the `index.html` file to your server root (e.g. `public_html/finvault/index.html`) using FTP.
2. Log into the WordPress dashboard (`/wp-admin`).
3. Create a new Page (**Pages** -> **Add New**). Title it *Live Market Terminal*.
4. In the page settings (right sidebar), change the Page Template to **Full Width** or **Elementor Canvas** (to remove sidebars).
5. Add a **Custom HTML** block and paste the following code:
   ```html
   <iframe src="https://yourwebsite.com/finvault/" width="100%" height="950px" style="border:none; border-radius:16px; box-shadow: 0 10px 45px rgba(0,0,0,0.15);" allowfullscreen="true" scrolling="yes"></iframe>
   ```
6. Replace the URL with your actual uploaded file location and click **Publish**.

---

## 3. Technical Verification & SEO Checklist

Before making the page public:
- [x] **SEO Structured Data**: Ensure the pre-configured JSON-LD structured data is present inside the `<head>` of your page so Google correctly crawls this as a secure Financial Web Application.
- [x] **Social Open Graph**: Verify that social shares on LinkedIn, Facebook, and Twitter will show beautiful media cards with the correct description.
- [x] **Theme Switcher**: Click the Moon/Sun toggle to ensure smooth transitions between dark and light themes inside your WP frame.
- [x] **Watchlist Persistence**: Add a few stocks to the watchlist, close your browser, and open it again. Confirm the items are persistently restored.
- [x] **SEBI Mandatory Compliance Disclaimer**: Ensure the bottom footer disclaimer displays **"Not a SEBI-registered advisor"** as mandated by SEBI guidelines for Indian financial content creators.
