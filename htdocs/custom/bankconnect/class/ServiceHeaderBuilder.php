<?php
/**
 * Builds the BankConnect <serviceHeader> element (mandatory on all operations).
 *
 * Fields per API / step-by-step guides:
 *  - organisationIdentification (mainRegistrationNumber, isoCountryCode)
 *  - functionIdentification (= Bank Connect ID / agreement number)
 *  - erpInformation (optional)
 *  - format (optional)
 *  - endToEndMessageId (max 35, unique per request)
 *  - createDateTime (Danish time, with offset)
 */

require_once __DIR__.'/BankConnectException.php';

class ServiceHeaderBuilder
{
    private string $mainRegistrationNumber = '';
    private string $isoCountryCode = 'DK';
    private string $functionIdentification = '';
    private string $endToEndMessageId = '';
    private string $erpSystem = 'Dolibarr';
    private string $erpVersion = '';
    private string $format = '';

    public function setOrganisation(string $mainRegistrationNumber, string $isoCountryCode = 'DK'): self
    {
        $this->mainRegistrationNumber = $mainRegistrationNumber;
        $this->isoCountryCode = $isoCountryCode;
        return $this;
    }

    public function setFunctionIdentification(string $id): self
    {
        $this->functionIdentification = $id;
        return $this;
    }

    public function setEndToEndMessageId(string $id): self
    {
        if (strlen($id) > 35) {
            throw new BankConnectException('endToEndMessageId max 35 characters');
        }
        $this->endToEndMessageId = $id;
        return $this;
    }

    public function setErp(string $system, string $version = ''): self
    {
        $this->erpSystem = $system;
        $this->erpVersion = $version;
        return $this;
    }

    public function setFormat(string $format): self
    {
        $this->format = $format;
        return $this;
    }

    public function build(): string
    {
        if ($this->mainRegistrationNumber === '' || $this->functionIdentification === '') {
            throw new BankConnectException('mainRegistrationNumber and functionIdentification are required');
        }
        if ($this->endToEndMessageId === '') {
            $this->endToEndMessageId = $this->generateId();
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen'));
        $createDateTime = $now->format('Y-m-d\TH:i:s.vP');

        $ns = 'http://bankconnect.dk/schema/2014';

        return '<serviceHeader xmlns="'.$ns.'">'
             . '<organisationIdentification>'
             . '<mainRegistrationNumber>'.$this->e($this->mainRegistrationNumber).'</mainRegistrationNumber>'
             . '<isoCountryCode>'.$this->e($this->isoCountryCode).'</isoCountryCode>'
             . '</organisationIdentification>'
             . '<functionIdentification>'.$this->e($this->functionIdentification).'</functionIdentification>'
             . '<erpInformation>'
             . '<erpsystem>'.$this->e($this->erpSystem).'</erpsystem>'
             . '<erpversion>'.$this->e($this->erpVersion).'</erpversion>'
             . '</erpInformation>'
             . '<format>'.$this->e($this->format).'</format>'
             . '<endToEndMessageId>'.$this->e($this->endToEndMessageId).'</endToEndMessageId>'
             . '<createDateTime>'.$createDateTime.'</createDateTime>'
             . '</serviceHeader>';
    }

    public function getEndToEndMessageId(): string
    {
        return $this->endToEndMessageId;
    }

    private function generateId(): string
    {
        return str_replace('-', '', sprintf(
            '%04x%04x%04x%04x%04x%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff),
            mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        ));
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
