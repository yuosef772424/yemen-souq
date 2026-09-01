package com.smsbridge.app.sms

import android.app.Activity
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import android.telephony.SmsManager
import androidx.core.content.ContextCompat
import kotlinx.coroutines.suspendCancellableCoroutine
import java.util.UUID
import java.util.concurrent.atomic.AtomicInteger
import kotlin.coroutines.resume

class SmsGateway(private val context: Context) {
    suspend fun send(to: String, body: String): Result<Unit> = runCatching {
        require(to.isNotBlank()) { "The destination number is empty." }
        require(body.isNotBlank()) { "The message body is empty." }
        val manager = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            context.getSystemService(SmsManager::class.java)
        } else {
            @Suppress("DEPRECATION") SmsManager.getDefault()
        }
        val parts = manager.divideMessage(body)
        awaitSend(manager, to, parts)
    }

    private suspend fun awaitSend(manager: SmsManager, to: String, parts: ArrayList<String>) = suspendCancellableCoroutine<Unit> { continuation ->
        val action = "${context.packageName}.SMS_SENT.${UUID.randomUUID()}"
        val remaining = AtomicInteger(parts.size)
        var finished = false
        val receiver = object : BroadcastReceiver() {
            override fun onReceive(receiverContext: Context, intent: Intent) {
                if (finished) return
                if (resultCode != Activity.RESULT_OK) {
                    finished = true
                    context.unregisterReceiver(this)
                    continuation.resumeWith(Result.failure(IllegalStateException(sendError(resultCode))))
                } else if (remaining.decrementAndGet() == 0) {
                    finished = true
                    context.unregisterReceiver(this)
                    continuation.resume(Unit)
                }
            }
        }
        ContextCompat.registerReceiver(context, receiver, IntentFilter(action), ContextCompat.RECEIVER_NOT_EXPORTED)
        continuation.invokeOnCancellation { if (!finished) context.unregisterReceiver(receiver) }
        val intents = ArrayList<PendingIntent>(parts.size)
        repeat(parts.size) { index ->
            val intent = Intent(action).setPackage(context.packageName).putExtra("part", index)
            intents += PendingIntent.getBroadcast(
                context,
                (action.hashCode() + index),
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )
        }
        if (parts.size == 1) {
            manager.sendTextMessage(to, null, parts.single(), intents.single(), null)
        } else {
            manager.sendMultipartTextMessage(to, null, parts, intents, null)
        }
    }

    private fun sendError(code: Int): String = when (code) {
        SmsManager.RESULT_ERROR_GENERIC_FAILURE -> "The carrier rejected the message."
        SmsManager.RESULT_ERROR_NO_SERVICE -> "There is no mobile service."
        SmsManager.RESULT_ERROR_NULL_PDU -> "Android rejected an invalid message."
        SmsManager.RESULT_ERROR_RADIO_OFF -> "Mobile radio is switched off."
        else -> "Android SMS error $code."
    }
}
