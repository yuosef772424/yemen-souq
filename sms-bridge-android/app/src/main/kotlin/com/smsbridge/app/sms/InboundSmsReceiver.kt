package com.smsbridge.app.sms

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import com.smsbridge.app.bridge.BridgeStore
import com.smsbridge.app.bridge.InboundMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

class InboundSmsReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return
        val pendingResult = goAsync()
        CoroutineScope(SupervisorJob() + Dispatchers.IO).launch {
            try {
                val config = BridgeStore(context.applicationContext).config()
                Telephony.Sms.Intents.getMessagesFromIntent(intent)
                    .groupBy { it.originatingAddress.orEmpty() }
                    .forEach { (sender, parts) ->
                        val body = parts.joinToString(separator = "") { it.messageBody.orEmpty() }
                        if (InboundFilter.accepts(config, sender, body)) {
                            BridgeStore(context.applicationContext).enqueueInbound(
                                InboundMessage(UUID.randomUUID().toString(), sender, body, utcTimestamp()),
                            )
                        }
                    }
            } finally {
                pendingResult.finish()
            }
        }
    }

    private fun utcTimestamp(): String = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US).apply {
        timeZone = java.util.TimeZone.getTimeZone("UTC")
    }.format(Date())
}
