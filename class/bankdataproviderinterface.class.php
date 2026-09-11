<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Common contract for bank data sources.
 *
 * A provider can be file based (CSV, CAMT, MT940) or API based (Wise, AISP, ...).
 * Provider-specific data must be normalized into BankStatement/BankTransaction objects.
 */
interface BankDataProviderInterface
{
    /** @return string Stable provider key */
    public function getKey();

    /** @return string Human-readable provider label */
    public function getLabel();

    /** @return string Source type, e.g. file or api */
    public function getSourceType();

    /**
     * Read data from provider-specific context.
     *
     * File providers receive for example array('path' => '/tmp/statement.csv').
     * API providers can receive account/date/auth context later.
     *
     * @param array<string,mixed> $context Provider-specific context
     * @return BankStatement
     * @throws Exception
     */
    public function fetch(array $context);
}
