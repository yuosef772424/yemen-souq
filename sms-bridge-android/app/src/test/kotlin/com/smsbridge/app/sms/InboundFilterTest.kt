package com.smsbridge.app.sms

import com.smsbridge.app.bridge.BridgeConfig
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class InboundFilterTest {
    @Test fun `rejects every message before a filter is configured`() {
        assertFalse(InboundFilter.accepts(BridgeConfig(), "+966500000000", "SQ123456"))
    }

    @Test fun `accepts an exact configured sender despite formatting`() {
        val config = BridgeConfig(allowedNumbers = setOf("+966 50 123 4567"))
        assertTrue(InboundFilter.accepts(config, "+966501234567", "any private text"))
    }

    @Test fun `accepts a configured leading code case insensitively`() {
        assertTrue(InboundFilter.accepts(BridgeConfig(requiredPrefix = "SQ-"), "+966500000000", "  sq-AB12 message"))
    }

    @Test fun `does not match the code in the middle of a message`() {
        assertFalse(InboundFilter.accepts(BridgeConfig(requiredPrefix = "SQ-"), "+966500000000", "bank notice SQ-AB12"))
    }
}
