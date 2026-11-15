# ZUNO backend

[Bagisto](https://bagisto.com) (Laravel + MySQL) for the ZUNO shop. It holds products, stock, customers and orders,
and has the admin panel. The ZUNO code is in `packages/Zuno/Storefront`. It provides the JSON API the frontend uses
(`/api/zuno/*`), shows custom-tee artwork in the admin, handles Google sign-in, and includes the `zuno:setup`
command that creates the starting catalogue from `packages/Zuno/Storefront/data/products.json` and `seed/`.

The frontend is the separate `zuno-frontend` repo.

## Run locally

Needs Docker Desktop.

```bash
cp .env.example .env          # set ADMIN_PASSWORD and APP_KEY (see the comments in the file)
docker compose up --build     # http://localhost:8000; the first start takes a few minutes
```

- Admin: http://localhost:8000/admin, using `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env`.
- Emails are caught by Mailpit at http://localhost:8025.
- Locally, edits under `packages/Zuno/Storefront` take effect without a rebuild.
- Run artisan as `www-data` so the web server can still write the files it creates:
  `docker compose exec -u www-data bagisto php artisan <command>`

## Production (Oracle Cloud)

`docker-compose.prod.yml` runs Bagisto, MySQL and Caddy, which serves HTTPS with an automatic certificate. The
frontend on Vercel forwards `/api/zuno/*` and `/storage/*` here. Caddy serves only the API, `/admin`, `/storage`
and the admin's assets; every other address (Bagisto's own storefront, its customer login and its installer) is
redirected to the React shop at `ZUNO_FRONTEND_URL`.

**Every push to `main` deploys.** The GitHub Action in `.github/workflows/deploy.yml` copies the repo to
`~/zuno-backend` on the server and runs `docker compose -f docker-compose.prod.yml up -d --build`. Products, orders
and customers are kept in Docker volumes, so deploying never resets them. Settings and passwords live only in
`~/zuno-backend/.env` on the server: `BACKEND_DOMAIN`, `ZUNO_FRONTEND_URL`, `APP_KEY`, `DB_PASSWORD`,
`DB_ROOT_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, plus the Razorpay, Google and SMTP keys.

The Action needs these settings in GitHub (repository **Settings → Secrets and variables → Actions**):

| Name | Kind | Value |
|---|---|---|
| `DEPLOY_HOST` | secret | the server's public IP |
| `DEPLOY_USER` | secret | `opc` |
| `DEPLOY_SSH_KEY` | secret | a private key used only for deploying, whose public key is in the server's `~/.ssh/authorized_keys` |
| `DEPLOY_KNOWN_HOSTS` | secret | output of `ssh-keyscan <server IP>` |
| `BACKEND_DOMAIN` | variable | e.g. `140-245-225-16.sslip.io` |

Useful commands on the server (`ssh zuno`):

```bash
cd ~/zuno-backend
sudo docker compose -f docker-compose.prod.yml ps              # is everything running?
sudo docker compose -f docker-compose.prod.yml logs -f bagisto # watch the backend's log
```

## Managing the shop (admin)

The ZUNO package trims Bagisto's admin to what the shop uses (`StorefrontServiceProvider::simplifyAdminMenu`), adds
the ZUNO logo, and offers only two product types when creating a product. Hidden pages still open from their address.

- **Home** (replaces Bagisto's dashboard, `AdminHomeController`): sales today and this month, the last 7 days,
  orders still to pack and ship (oldest first), and sizes with 5 or fewer left.
- **Products → All products**: price, photos, stock (per colour × size), categories, related products.
  New clothing: type "Clothing in sizes / colours", family "ZUNO clothing".
- **Products → Categories**: the chips on the shop page. **Products → Sizes & colours**: the size and colour options.
- **Customers → Reviews**: approve reviews. **Customers → All customers**: accounts. Customers sign in only with
  Google; there are no customer passwords.
- **Coupons**: coupon codes (Bagisto's cart rules).
- **Reports**: sales, customer and product reports with charts.
- **Orders**: invoices, shipments and refunds are made from an order's page. Custom-tee orders show the mockup and downloadable artwork. Tracking numbers added to
  shipments are shown to the customer.
- **Store settings**: Bagisto's configuration (payments, shipping, emails, logo).
- The **Custom tee** product (SKU `zuno-custom-tee`) sets the custom-tee price. Disable it to pause custom orders.

`zuno:setup` runs on every start. It only creates what's missing and never overwrites admin edits. To rebuild the
catalogue from `products.json`, which deletes and re-creates those products, run
`php artisan zuno:setup --fresh`.
