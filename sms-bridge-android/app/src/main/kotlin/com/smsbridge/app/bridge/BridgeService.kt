package com.smsbridge.app.bridge

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.smsbridge.app.sms.SmsGateway
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.math.min

/**
 * A user-visible foreground service is necessary here: Android's periodic scheduler has a 15-minute
 * minimum and is unsuitable for a pull queue that must send SMS promptly.
 */
class BridgeService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var loop: Job? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }
        showForegroundNotification()
        if (loop?.isActive != true) loop = scope.launch { runLoop() }
        return START_NOT_STICKY
    }

    override fun onDestroy() {
        loop?.cancel()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private suspend fun runLoop() {
        val store = BridgeStore(applicationContext)
        var backoffSeconds = 0
        while (scope.isActive) {
            val config = store.config()
            if (!config.enabled || !config.isReady()) {
                stopSelf()
                return
            }
            try {
                val api = BridgeApi(config)
                flushInbound(store, api)
                val claimed = api.claim()
                if (!claimed.claim_token.isNullOrBlank() && claimed.messages.isNotEmpty()) {
                    val gateway = SmsGateway(applicationContext)
                    val results = claimed.messages.map { message ->
                        val result = withTimeoutOrNull(SEND_TIMEOUT_MS) { gateway.send(message.to, message.body) }
                        when {
                            result == null -> SendResult(message.id, "failed", "Timed out waiting for Android to send the SMS.")
                            result.isSuccess -> SendResult(message.id, "sent")
                            else -> SendResult(message.id, "failed", result.exceptionOrNull()?.message ?: "Unknown SMS error")
                        }
                    }
                    api.confirm(claimed.claim_token, results)
                }
                backoffSeconds = config.pollIntervalSeconds.coerceIn(MIN_POLL_SECONDS, MAX_POLL_SECONDS)
            } catch (error: BridgeApiException) {
                // A rejected token will not recover by calling the server every few seconds.
                backoffSeconds = if (error.status == 401) 60 else nextBackoff(backoffSeconds, config.pollIntervalSeconds)
            } catch (_: Exception) {
                backoffSeconds = nextBackoff(backoffSeconds, config.pollIntervalSeconds)
            }
            delay(backoffSeconds.coerceIn(MIN_POLL_SECONDS, MAX_BACKOFF_SECONDS) * 1_000L)
        }
    }

    private suspend fun flushInbound(store: BridgeStore, api: BridgeApi) {
        val pending = store.queuedInbound()
        if (pending.isEmpty()) return
        api.uploadInbound(pending)
        store.removeInbound(pending.mapTo(mutableSetOf()) { it.id })
    }

    private fun nextBackoff(current: Int, configured: Int): Int = min(
        MAX_BACKOFF_SECONDS,
        maxOf(configured.coerceIn(MIN_POLL_SECONDS, MAX_POLL_SECONDS), if (current == 0) MIN_POLL_SECONDS else current * 2),
    )

    private fun showForegroundNotification() {
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel(CHANNEL_ID, "SMS Bridge", NotificationManager.IMPORTANCE_LOW))
        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(android.R.drawable.stat_notify_sync)
            .setContentTitle("SMS Bridge is running")
            .setContentText("Pulling the configured server and forwarding only matching SMS messages.")
            .setOngoing(true)
            .build()
        // remoteMessaging (not dataSync) so Android 15+'s 6-hour-per-24h foreground service
        // budget does not apply; a dataSync type would silently stop the bridge every day
        // until the user reopened the app. remoteMessaging needs API 34 (UPSIDE_DOWN_CAKE).
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_REMOTE_MESSAGING)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    /** Defensive: some OEMs enforce their own foreground-service budgets outside AOSP policy. */
    override fun onTimeout(startId: Int, fgsType: Int) = stopSelf()

    companion object {
        private const val CHANNEL_ID = "sms_bridge_service"
        private const val NOTIFICATION_ID = 1001
        private const val ACTION_STOP = "com.smsbridge.app.STOP"
        private const val MIN_POLL_SECONDS = 5
        private const val MAX_POLL_SECONDS = 300
        private const val MAX_BACKOFF_SECONDS = 120
        private const val SEND_TIMEOUT_MS = 60_000L

        fun start(context: Context) = ContextCompat.startForegroundService(context, Intent(context, BridgeService::class.java))
        fun stop(context: Context) = context.stopService(Intent(context, BridgeService::class.java).setAction(ACTION_STOP))
    }
}
