# Fin Vault Swarm Financial Terminal — System & Feature Documentation

Welcome to the **Fin Vault Swarm Financial Terminal** system guide. This document provides a comprehensive overview of the terminal’s interactive features, advanced financial engines, and backend data-fetching architecture.

---

## 1. Core Visual & Interactive Features

### 📊 Market Mood Index (MMI)
The **Market Mood Index** is a high-fidelity visual gauge designed to replicate the visual style and analytical behavior of **Tickertape's MMI**:
*   **Mathematical Calculation**: Computed dynamically in the browser based on the **Advance-Decline Ratio (ADR)** of the Nifty 50 constituent stocks combined with the actual Nifty index percentage change:
    $$\text{MMI Score} = \text{Clamp}\left(\left(\frac{\text{Advances}}{\text{Total Stocks}} \times 100\right) + (\text{Nifty \% Change} \times 12), \, 5, \, 95\right)$$
*   **Tickertape Ranges**: Segmented into four exact score bounds:
    *   **Extreme Fear** (<30): Indicates high market panic and spikes in volatility indices.
    *   **Fear** (30–50): Reflects cautious retail sentiments and high option put coverage.
    *   **Greed** (50–70): Driven by strong domestic capital inflows and high derivative open interest.
    *   **Extreme Greed** (>70): Indicates FOMO and historically stretched valuation metrics.
*   **Dynamic SVG Gauge Highlights**: Re-engineered using a flat-bottomed semi-circular SVG. When the score is updated, the active sector's fill opacity transitions to `0.15` and its outer double-outline border lights up at `100%` opacity. Inactive sectors remain softly faded.
*   **Smooth Sweep Needle**: Pivots smoothly around the center coordinate `(50, 55)` on a 180° sweep (from `-90deg` at 0 score to `90deg` at 100 score).

---

### 🟢 Dynamic Market State Engine
The terminal actively tracks domestic trading hours to light up the status badge dynamically:
*   **Trading Hours (IST)**: Operates between **09:15 AM and 03:30 PM**, Monday through Friday.
*   **Live States**:
    *   `OPEN (LIVE)`: Green pulsing dot during market hours.
    *   `CLOSED (WEEKEND)` / `CLOSED (AFTER-HOURS)`: Amber lock icon outside trading windows.

---

### 📈 Nifty 50 Active Movers Hub
A dynamic frontend sorting grid that organizes NSE constituent quotes:
*   **Top Gainers & Losers**: Automatically calculates the **Top 20 Gainers** and **Top 20 Losers** based on real-time percentage change from the previous close.
*   **Screener Filters**: Filter constituent rows by technical ratings (`Strong Buy`, `Buy`, `Neutral`, `Sell`, `Strong Sell`) which are calculated dynamically from percentage changes.
*   **Flash Ticks**: Rows briefly flash green or red whenever a new real-time price tick occurs.

---

### ⭐ Synced Watchlist & Security
*   **Pinning Mechanism**: Toggle any asset with the star icon to pins it directly to your Watchlist tab.
*   **Zero-Maintenance Synchronization**: Dual-layer storage:
    *   **LocalStorage**: Saves state locally in the user's browser.
    *   **WordPress Sync REST API**: Logged-in users automatically sync their watchlists to their WordPress server-side user metadata profile securely.

---

### 💵 Indian Commodity MCX Calibration
International commodity futures trade in US Dollars per Troy Ounce. To display realistic domestic MCX prices, the terminal mathematically converts spot commodity feeds in real time:
*   **Gold 24K (per 10g)**:
    $$\text{Gold 10g INR} = \left(\frac{\text{USD/Ounce Price}}{31.1035}\right) \times 10 \times \text{USD/INR exchange rate}$$
*   **Silver (per 1kg)**:
    $$\text{Silver 1kg INR} = \text{USD/Ounce Price} \times 32.1507 \times \text{USD/INR exchange rate}$$
*   **Brent Crude**: Remains in standard USD per barrel.

---

## 2. Data Fetching Architecture

To maintain 100% accurate, live, real-time quotes without paying for expensive private APIs, the terminal employs a **double-redundant backend-proxy architecture**.

```
                   [ Browser Client (index.html) ]
                                  │
          1. Try Local WordPress API (Cached 60s)
                                  ├───────────────────────┐
                                  ▼ [Success]             ▼ [Fail / Standalone]
                       [ /wp-json/finvault/v1/market-data ]   2. Fallback: allorigins CORS Proxy
                                  │                                  │
                       ┌──────────┴──────────┐                       ▼
                       ▼                     ▼             [ https://query1.finance.yahoo.com ]
                [ NSE India API ]     [ Yahoo Finance v8 ]
                (Cookie Session)      (Crumb Handshake)
```

### Stream 1: WordPress Server-Side Proxy (Priority 1)
For WordPress deployments, a custom plugin (`finvault-watchlist-sync.php` v2.0.0) acts as a high-performance cache and CORS bypass:
1.  **NSE India API (unofficial public endpoint)**:
    *   NSE blocks direct API calls without session context. The plugin visits `nseindia.com` homepage first, harvests the browser session cookies, caches them for 4 minutes, and executes secure backend fetches.
    *   Pulls indices (`^NSEI`, `^NSEBANK`, `^CNXIT`) and **all 50 constituent stocks** in a single batch.
2.  **Yahoo Finance v8 API (crumb handshake)**:
    *   Yahoo requires cookie tokens and crumb handshakes. The plugin visits Yahoo Finance, retrieves crumb variables, caches them for 50 minutes, and makes secure fetches for global indices and commodities (`^DJI`, `^GSPC`, `^IXIC`, `GC=F`, `SI=F`, `BZ=F`, `INR=X`).
3.  **Transients Caching**: Aggregates all streams and caches the final merged payload for 60 seconds to prevent IP throttling and ensure zero server lag.

### Stream 2: Standalone CORS Proxy Fallback (Priority 2)
If the terminal is run as a standalone HTML page (e.g. locally at `http://localhost:3002` or standalone GitHub pages) without a WordPress backend:
*   **Direct API Fallback**: The client-side JavaScript automatically compiles all 55 stock tickers + global indices and requests them through the free, public `api.allorigins.win` CORS proxy.
*   **CORS Bypass**: Requests are proxied directly to `query1.finance.yahoo.com/v7/finance/quote`, bypassing the browser's origin security checks.

---

## 3. How to Validate & Verify
1.  **Console Terminal Log**: Open the browser's Developer Tools (F12) to see active Swarm logs tracking live feed updates and API handshake sources.
2.  **Verify Movers**: Open the terminal and verify Eicher Motors (`EICHERMOT`) is showing ~₹7,356.00 and BPCL is showing ~₹307.30 (post split).
3.  **Manual Refresh**: Click the circular arrows button inside the **Live Engine Status** card to force an instant, non-cached fetch.
