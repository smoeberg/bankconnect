<?php
/**
 * Pain002Parser – parses ISO 20022 pain.002.001.03 payment status reports.
 *
 * Security contract:
 * - reject oversized / DTD / entity-bearing XML
 * - require a pain.002 customer payment status report
 * - preserve the bank status verbatim
 * - map only known statuses; unknown statuses become UNKNOWN
 */
require_once __DIR__.'/BankConnectException.php';

class Pain002Parser
{
    public const INTERNAL_ACCEPTED = 'accepted';
    public const INTERNAL_PENDING = 'pending';
    public const INTERNAL_PARTIAL = 'partial';
    public const INTERNAL_REJECTED = 'rejected';
    public const INTERNAL_UNKNOWN = 'unknown';

    public const MAX_XML_BYTES = 2 * 1024 * 1024;

    /**
     * @return array{
     *   msg_id:?string,
     *   original_msg_id:?string,
     *   group_status:?string,
     *   transactions:list<array{
     *      end_to_end_id:string,status:string,semantic_status:string,
     *      reason_code:?string,reason_text:?string,requires_manual_review:bool
     *   }>
     * }
     */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new BankConnectException('Empty pain.002 XML');
        }
        if (strlen($xml) > self::MAX_XML_BYTES) {
            throw new BankConnectException('pain.002 XML exceeds the 2 MB limit');
        }
        if (preg_match('/<!DOCTYPE\s/i', $xml) || preg_match('/<!ENTITY\s/i', $xml)) {
            throw new BankConnectException('pain.002 XML with DTD/entity declarations is not allowed');
        }

        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string(
            $xml,
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        if ($doc === false) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            throw new BankConnectException('Malformed pain.002 XML');
        }
        libxml_use_internal_errors($prev);

        $reports = $doc->xpath('//*[local-name()="CstmrPmtStsRpt"]') ?: [];
        if (!$reports) {
            throw new BankConnectException('pain.002 customer payment status report not found');
        }
        $report = $reports[0];

        $result = [
            'msg_id' => null,
            'original_msg_id' => null,
            'group_status' => null,
            'transactions' => [],
        ];

        $msgId = $this->first($report, './*[local-name()="GrpHdr"]/*[local-name()="MsgId"]');
        $originalMsgId = $this->first($report, './*[local-name()="OrgnlGrpInfAndSts"]/*[local-name()="OrgnlMsgId"]');
        $groupStatus = $this->first($report, './*[local-name()="OrgnlGrpInfAndSts"]/*[local-name()="GrpSts"]');

        $result['msg_id'] = $msgId !== null ? trim((string)$msgId) : null;
        $result['original_msg_id'] = $originalMsgId !== null ? trim((string)$originalMsgId) : null;
        $result['group_status'] = $groupStatus !== null ? strtoupper(trim((string)$groupStatus)) : null;

        $txNodes = $report->xpath('.//*[local-name()="TxInfAndSts"]') ?: [];
        foreach ($txNodes as $tx) {
            $e2e = $this->first($tx, './*[local-name()="OrgnlEndToEndId"]');
            $sts = $this->first($tx, './*[local-name()="TxSts"]');
            if ($e2e === null && $sts === null) {
                continue;
            }

            $status = $sts !== null && trim((string)$sts) !== ''
                ? strtoupper(trim((string)$sts))
                : 'UNKNOWN';
            $semantic = self::mapToInternalStatus($status);
            $reasonCode = $this->first($tx, './*[local-name()="StsRsnInf"]/*[local-name()="Rsn"]/*[local-name()="Cd"]');
            $reasonText = $this->first($tx, './*[local-name()="StsRsnInf"]/*[local-name()="AddtlInf"]');

            $result['transactions'][] = [
                'end_to_end_id' => $e2e !== null ? trim((string)$e2e) : '',
                'status' => $status,
                'semantic_status' => $semantic,
                'reason_code' => $reasonCode !== null ? trim((string)$reasonCode) : null,
                'reason_text' => $reasonText !== null ? trim((string)$reasonText) : null,
                'requires_manual_review' => $semantic === self::INTERNAL_UNKNOWN,
            ];
        }

        return $result;
    }

    public static function mapToInternalStatus(string $pain002Status): string
    {
        return match (strtoupper(trim($pain002Status))) {
            'ACCP', 'ACSC', 'ACSP', 'ACWC', 'ACTC' => self::INTERNAL_ACCEPTED,
            'PART' => self::INTERNAL_PARTIAL,
            'PDNG', 'RCVD' => self::INTERNAL_PENDING,
            'RJCT' => self::INTERNAL_REJECTED,
            default => self::INTERNAL_UNKNOWN,
        };
    }

    public static function mapGroupToInternalStatus(?string $groupStatus): string
    {
        return self::mapToInternalStatus($groupStatus ?? 'UNKNOWN');
    }

    private function first($ctx, string $xpath)
    {
        $nodes = @$ctx->xpath($xpath);
        return !empty($nodes) ? $nodes[0] : null;
    }
}
