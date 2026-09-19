<?php
/**
 * Pain002Parser – parses ISO 20022 pain.002.001.03 (payment status report).
 *
 * Used after getStatus to update batch / batch_line statuses.
 *
 * Status codes (common):
 *   ACCP – AcceptedCustomerProfile / Accepted
 *   ACSP – AcceptedSettlementInProcess
 *   ACWC – AcceptedWithChange
 *   PART – PartiallyAccepted
 *   PDNG – Pending
 *   RJCT – Rejected
 *   RCVD – Received
 */

require_once __DIR__.'/BankConnectException.php';

class Pain002Parser
{
    /**
     * @param string $xml  Full pain.002 document or CorporateMessage content
     * @return array{
     *   msg_id:?string,
     *   original_msg_id:?string,
     *   group_status:?string,
     *   transactions: list<array{end_to_end_id:string, status:string, reason_code:?string, reason_text:?string}>
     * }
     */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new BankConnectException('Empty pain.002 XML');
        }

        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            throw new BankConnectException('Malformed pain.002 XML');
        }

        // Register default ISO namespace if present
        $doc->registerXPathNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.002.001.03');

        $result = [
            'msg_id'          => null,
            'original_msg_id' => null,
            'group_status'    => null,
            'transactions'    => [],
        ];

        // Try namespaced and non-namespaced paths
        $msgId = $this->xpathFirst($doc, [
            '//p:GrpHdr/p:MsgId',
            '//GrpHdr/MsgId',
            '//*[local-name()="MsgId"]',
        ]);
        if ($msgId !== null) {
            $result['msg_id'] = (string) $msgId;
        }

        $orgnlMsgId = $this->xpathFirst($doc, [
            '//p:OrgnlGrpInfAndSts/p:OrgnlMsgId',
            '//OrgnlGrpInfAndSts/OrgnlMsgId',
            '//*[local-name()="OrgnlMsgId"]',
        ]);
        if ($orgnlMsgId !== null) {
            $result['original_msg_id'] = (string) $orgnlMsgId;
        }

        $grpSts = $this->xpathFirst($doc, [
            '//p:OrgnlGrpInfAndSts/p:GrpSts',
            '//OrgnlGrpInfAndSts/GrpSts',
            '//*[local-name()="GrpSts"]',
        ]);
        if ($grpSts !== null) {
            $result['group_status'] = strtoupper((string) $grpSts);
        }

        // Transaction level
        $txNodes = $doc->xpath('//p:TxInfAndSts') ?: $doc->xpath('//TxInfAndSts') ?: [];
        if (empty($txNodes)) {
            // local-name fallback
            $txNodes = $doc->xpath('//*[local-name()="TxInfAndSts"]') ?: [];
        }

        foreach ($txNodes as $tx) {
            $tx->registerXPathNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.002.001.03');

            $e2e = $this->xpathFirst($tx, [
                './p:OrgnlEndToEndId',
                './OrgnlEndToEndId',
                './/*[local-name()="OrgnlEndToEndId"]',
            ]);
            $sts = $this->xpathFirst($tx, [
                './p:TxSts',
                './TxSts',
                './/*[local-name()="TxSts"]',
            ]);
            $rsnCd = $this->xpathFirst($tx, [
                './p:StsRsnInf/p:Rsn/p:Cd',
                './StsRsnInf/Rsn/Cd',
                './/*[local-name()="Cd"]',
            ]);
            $rsnAddtl = $this->xpathFirst($tx, [
                './p:StsRsnInf/p:AddtlInf',
                './StsRsnInf/AddtlInf',
                './/*[local-name()="AddtlInf"]',
            ]);

            if ($e2e === null && $sts === null) {
                continue;
            }

            $result['transactions'][] = [
                'end_to_end_id' => $e2e !== null ? (string) $e2e : '',
                'status'        => $sts !== null ? strtoupper((string) $sts) : 'UNKNOWN',
                'reason_code'   => $rsnCd !== null ? (string) $rsnCd : null,
                'reason_text'   => $rsnAddtl !== null ? (string) $rsnAddtl : null,
            ];
        }

        return $result;
    }

    /**
     * Map pain.002 status to our batch_line status.
     */
    public static function mapToInternalStatus(string $pain002Status): string
    {
        $s = strtoupper($pain002Status);
        return match ($s) {
            'ACCP', 'ACSC', 'ACSP', 'ACWC', 'ACTC', 'ACWC' => 'accepted',
            'RJCT', 'RJCT' => 'rejected',
            'PDNG', 'RCVD', 'PART' => 'pending',
            default => strtolower($s) ?: 'unknown',
        };
    }

    /**
     * @param \SimpleXMLElement $ctx
     * @param string[]          $paths
     * @return \SimpleXMLElement|null
     */
    private function xpathFirst($ctx, array $paths)
    {
        foreach ($paths as $path) {
            $nodes = @$ctx->xpath($path);
            if (!empty($nodes)) {
                return $nodes[0];
            }
        }
        return null;
    }
}
