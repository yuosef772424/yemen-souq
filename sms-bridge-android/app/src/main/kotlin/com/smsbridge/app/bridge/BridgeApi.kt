package com.smsbridge.app.bridge

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.encodeToJsonElement
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import java.net.HttpURLConnection
import java.net.URL

@Serializable data class ClaimedMessage(val id: Long, val to: String, val body: String)
@Serializable data class ClaimResponse(val claim_token: String? = null, val messages: List<ClaimedMessage> = emptyList())
@Serializable data class SendResult(val id: Long, val status: String, val error: String? = null)

class BridgeApi(private val config: BridgeConfig) {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
    private val root = config.serverUrl.trimEnd('/') + "/api/v1/sms"

    suspend fun claim(): ClaimResponse = post("/claim", buildJsonObject {
        put("worker", config.workerId)
        put("channel", "sms")
    }).let { json.decodeFromString(it) }

    suspend fun confirm(claimToken: String, results: List<SendResult>) {
        post("/confirm", buildJsonObject {
            put("claim_token", claimToken)
            put("results", json.encodeToJsonElement(results))
        })
    }

    suspend fun uploadInbound(messages: List<InboundMessage>) {
        post("/inbound", buildJsonObject {
            put("messages", json.encodeToJsonElement(messages.map {
                buildJsonObject {
                    put("client_message_id", it.id)
                    put("from", it.from)
                    put("body", it.body)
                    put("received_at", it.receivedAt)
                }
            }))
        })
    }

    private suspend fun post(path: String, body: JsonElement): String = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
        val connection = (URL(root + path).openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 25_000
            readTimeout = 25_000
            doOutput = true
            setRequestProperty("Authorization", "Bearer ${config.apiToken}")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
        }
        try {
            connection.outputStream.bufferedWriter(Charsets.UTF_8).use { it.write(json.encodeToString(JsonElement.serializer(), body)) }
            val status = connection.responseCode
            val response = (if (status in 200..299) connection.inputStream else connection.errorStream)
                ?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            if (status !in 200..299) throw BridgeApiException(status, response.take(300))
            response
        } finally {
            connection.disconnect()
        }
    }
}

class BridgeApiException(val status: Int, message: String) : Exception("Server returned $status: $message")
