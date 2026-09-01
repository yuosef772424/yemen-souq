package com.smsbridge.app.bridge

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * يعيد تشغيل الجسر بعد إعادة تشغيل الجهاز أو تحديث التطبيق،
 * فقط إن كان المستخدم قد فعّله مسبقاً. بلا هذا المستقبل
 * يبقى الجسر متوقفاً بصمت بعد كل reboot حتى يفتح المستخدم التطبيق يدوياً.
 */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action !in BOOT_ACTIONS) return
        val pending = goAsync()
        CoroutineScope(SupervisorJob() + Dispatchers.IO).launch {
            try {
                val config = BridgeStore(context.applicationContext).config()
                if (config.enabled && config.isReady()) {
                    BridgeService.start(context.applicationContext)
                }
            } finally {
                pending.finish()
            }
        }
    }

    companion object {
        private val BOOT_ACTIONS = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED,
        )
    }
}
