package com.smsbridge.app.sms

import com.smsbridge.app.bridge.BridgeConfig

/**
 * A message is allowed by a known sender OR a configured leading code.  The default is deny,
 * so an unfinished setup can never upload a user's whole SMS inbox.
 */
object InboundFilter {
    fun accepts(config: BridgeConfig, sender: String, body: String): Boolean {
        val numberMatches = normalizeNumber(sender) in config.allowedNumbers.map(::normalizeNumber).toSet()
        val prefix = config.requiredPrefix.trim()
        val prefixMatches = prefix.isNotEmpty() && body.trimStart().startsWith(prefix, ignoreCase = true)
        return numberMatches || prefixMatches
    }

    fun parseNumbers(value: String): Set<String> = value
        .split(',', '\n', ';')
        .map(::normalizeNumber)
        .filter(String::isNotEmpty)
        .toSet()

    private fun normalizeNumber(value: String): String = value.filter { it.isDigit() || it == '+' }
}
