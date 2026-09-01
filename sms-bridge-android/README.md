# SMS Bridge Android

Standalone Android companion for a phone-pull SMS worker server module (`claim` / `confirm` / `inbound`). The phone initiates all network calls, so it works behind carrier NAT (CGNAT) without opening a port on the phone.

## Build

```powershell
cd D:\laravel\yemen-souq\sms-bridge-android
.\gradlew.bat assembleDebug
```

The debug APK is written to `app\build\outputs\apk\debug\app-debug.apk`.

## Server setup (yemen-souq)

From the Laravel project root, migrate and generate the worker secret — this server uses **one shared secret for every phone**, not a per-device credential:

```bash
php artisan migrate
php artisan sms:token
```

Copy the printed token into the app (server URL + token). It is shown once, encrypted locally with an Android Keystore key, and never kept in the app's ordinary DataStore config file.

Enable the channel in `.env`:

```
SMS_ENABLED=true
SMS_WORKER_TOKEN=<from sms:token above>
PHONE_VERIFICATION_DRIVER=sms
```

An admin queues a phone-verification code through the normal `PhoneVerificationService` flow (`OutboxSender` picks it up automatically once `SMS_ENABLED=true`); WhatsApp remains the automatic failover channel server-side (`sms.failover.sms`) — the phone doesn't need to know about it, it only ever claims `channel=sms` rows.

The phone uses these authenticated endpoints (`app/Modules/Sms/Routes/api.php`):

- `POST /api/v1/sms/claim` — claims a batch of `sms`-channel messages for `sms.worker.claim_ttl_seconds` (120s default).
- `POST /api/v1/sms/confirm` — records `sent` or `failed` for that exact claim, in one call for the whole batch.
- `POST /api/v1/sms/inbound` — uploads only messages the phone already matched locally (sender number or `SQ` prefix — see `SMS_INBOUND_PREFIX`); requires `SMS_INBOUND_ENABLED=true` server-side for the reverse-verification channel.
- `GET /api/v1/sms/health` — queue depth and age, for `sms:status`-style monitoring.

## Operational limits

- The user must grant `SEND_SMS` and `RECEIVE_SMS`; the default SIM is used.
- The ongoing notification is intentional: it is a foreground service, needed because Android's periodic scheduler cannot poll at a seconds-level interval. Default poll interval is 5 seconds (configurable 5–300s in-app), matching the old Termux worker's default cadence — stay mindful of `sms.worker.rate_limit` (120 req/min by default) and the carrier's own outbound SMS throttling.
- The service type is `remoteMessaging`, not `dataSync`: Android 15+ caps `dataSync` foreground services at 6 cumulative hours per 24-hour period, after which the OS refuses to restart the service until the user reopens the app — `remoteMessaging` is exempt from that budget and matches the app's actual job (relaying text messages).
- Before public Google Play distribution, complete the restricted SMS-permission policy requirements (or distribute only through an approved managed/private channel). See [Google Play SMS policy](https://support.google.com/googleplay/android-developer/answer/10208820).
- Sending success is the modem/API acceptance callback, not carrier delivery confirmation. This is the strongest status available to an app sending through a physical SIM — same limitation the old `souq-sms-worker.sh` had with `termux-sms-send`.
