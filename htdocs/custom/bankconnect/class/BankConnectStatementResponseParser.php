<?php
/** Extracts exactly one ISO 20022 CAMT document from a BankConnect SOAP response. */
class BankConnectStatementResponseParser
{
	/** Read the continuation flag defined on the BankConnect corporateMessage. */
	public function hasMoreMessages(string $responseXml): bool
	{
		$xpath = new DOMXPath($this->loadResponse($responseXml));
		$messages = $xpath->query('//*[local-name()="corporateMessage"]');
		if (!$messages || $messages->length === 0) {
			// Older fixtures and direct CAMT responses have no BankConnect wrapper.
			return false;
		}
		if ($messages->length !== 1) {
			throw new BankConnectException('BankConnect response contains multiple corporate messages');
		}
		$flags = $xpath->query('./*[local-name()="severalMessages"]', $messages->item(0));
		if (!$flags || $flags->length !== 1) {
			throw new BankConnectException('BankConnect response has no unique severalMessages flag');
		}
		$value = trim((string)$flags->item(0)->textContent);
		if ($value !== 'true' && $value !== '1' && $value !== 'false' && $value !== '0') {
			throw new BankConnectException('BankConnect response has an invalid severalMessages flag');
		}
		return $value === 'true' || $value === '1';
	}

	/**
	 * Extract a CAMT document when the operation returned one.
	 *
	 * A structurally valid response with no non-empty content is the only case
	 * treated as "no data". Invalid or ambiguous payloads still fail closed.
	 */
	public function extractOptional(string $responseXml): ?string
	{
		$document = $this->loadResponse($responseXml);
		$xpath = new DOMXPath($document);
		$direct = $xpath->query('//*[local-name()="Document" and starts-with(namespace-uri(), "urn:iso:std:iso:20022:tech:xsd:camt.")]');
		if ($direct && $direct->length > 0) {
			return $this->extract($responseXml);
		}

		$contentNodes = $xpath->query('//*[local-name()="content"]');
		foreach ($contentNodes ?: [] as $contentNode) {
			if (trim((string)$contentNode->textContent) !== '') {
				return $this->extract($responseXml);
			}
		}
		return null;
	}

	public function extract(string $responseXml): string
	{
		$document = $this->loadResponse($responseXml);

		$xpath = new DOMXPath($document);
		$direct = $xpath->query('//*[local-name()="Document" and starts-with(namespace-uri(), "urn:iso:std:iso:20022:tech:xsd:camt.")]');
		if ($direct && $direct->length === 1) {
			return $document->saveXML($direct->item(0));
		}
		if ($direct && $direct->length > 1) {
			throw new BankConnectException('BankConnect response contains multiple CAMT documents');
		}

		$candidates = [];
		$contentNodes = $xpath->query('//*[local-name()="content"]');
		foreach ($contentNodes ?: [] as $contentNode) {
			$value = trim((string)$contentNode->textContent);
			if ($value === '') {
				continue;
			}
			$decoded = base64_decode($value, true);
			if ($decoded === false) {
				$decoded = html_entity_decode($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
			}
			if (substr($decoded, 0, 2) === "\x1f\x8b") {
				$decoded = gzdecode($decoded);
				if ($decoded === false) {
					throw new BankConnectException('BankConnect statement gzip payload is invalid');
				}
			}
			if ($this->isCamtDocument($decoded)) {
				$candidates[] = $decoded;
			}
		}

		if (count($candidates) !== 1) {
			throw new BankConnectException(count($candidates) === 0
				? 'BankConnect response contains no CAMT statement'
				: 'BankConnect response contains multiple CAMT statements');
		}
		return $candidates[0];
	}

	private function loadResponse(string $responseXml): DOMDocument
	{
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadXML($responseXml, LIBXML_NONET | LIBXML_NOBLANKS);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			throw new BankConnectException('BankConnect statement response is malformed XML');
		}
		return $document;
	}

	private function isCamtDocument(string $xml): bool
	{
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded || !$document->documentElement) {
			return false;
		}
		return $document->documentElement->localName === 'Document'
			&& str_starts_with((string)$document->documentElement->namespaceURI, 'urn:iso:std:iso:20022:tech:xsd:camt.');
	}
}
