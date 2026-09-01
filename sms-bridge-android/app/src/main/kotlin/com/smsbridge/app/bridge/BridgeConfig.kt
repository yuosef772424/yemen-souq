package com.smsbridge.app.bridge

import kotlinx.serialization.Serializable
import kotlinx.serialization.Transient
import java.util.UUID

@Serializable
data class BridgeConfig(
    val serverUrl: String = "",
    /** The token is deliberately excluded from DataStore serialization; BridgeStore keeps it in Android Keystore. */
    @Transient val apiToken: String = "",
    val workerId: String = "",
    val allowedNumbers: Set<String> = emptySet(),
    val requiredPrefix: String = "",
    val pollIntervalSeconds: Int = 30,
    val enabled: Boolean = false,
) {
    fun isReady(): Boolean = serverUrl.startsWith("https://") && apiToken.isNotBlank() && workerId.isNotBlank()

    fun withWorkerId(): BridgeConfig = if (workerId.isBlank()) copy(workerId = UUID.randomUUID().toString()) else this
}

@Serializable
data class InboundMessage(
    val id: String,
    val from: String,
    val body: String,
    val receivedAt: String,
)
