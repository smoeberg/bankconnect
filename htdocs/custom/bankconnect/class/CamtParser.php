<?php
/**
 * CamtParser – parses camt.053 / camt.054 into safe BankTransaction DTOs.
 *
 * Statement and transaction identities are preserved for database idempotency.
 */
require_once __DIR__.'/BankTransaction.php';

class CamtParser
{
    public const MAX_XML_BYTES = 10 * 1024 * 1024;

    public function parse(string $xml): array
    {
        if (trim($xml)==='') throw new RuntimeException('Empty XML');
        if (strlen($xml)>self::MAX_XML_BYTES) throw new RuntimeException('XML too large: maximum size is 10MB');
        if (preg_match('/<!DOCTYPE\s/i',$xml)||preg_match('/<!ENTITY\s/i',$xml)) throw new RuntimeException('XML with DTD/entity declarations is not allowed');

        $prev=libxml_use_internal_errors(true);
        $root=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        if($root===false){
            libxml_clear_errors(); libxml_use_internal_errors($prev);
            throw new RuntimeException('Malformed camt XML');
        }
        libxml_use_internal_errors($prev);

        if(!in_array($root->getName(),['Document','BkToCstmrStmt','BkToCstmrAcctRpt','BkToCstmrDbtCdtNtfctn'],true)){
            throw new RuntimeException('Unsupported CAMT document root: '.$root->getName());
        }

        $statementId=$this->extractStatementId($root);
        $txs=[];
        $entries=$root->xpath('//*[local-name()="Ntry"]')?:[];

        foreach($entries as $entryIndex=>$ntry){
            $creditDebit=strtoupper((string)($this->first($ntry,'./*[local-name()="CdtDbtInd"]')??'CRDT'));
            if(!in_array($creditDebit,['CRDT','DBIT'],true)) throw new RuntimeException('Invalid CAMT credit/debit indicator');

            $reversalValue=strtoupper(trim((string)($this->first($ntry,'./*[local-name()="RvslInd"]')??'')));
            $isReversal=in_array($reversalValue,['TRUE','1'],true);

            $dateEl=$this->first($ntry,'./*[local-name()="BookgDt"]/*[local-name()="Dt"]')??$this->first($ntry,'./*[local-name()="ValDt"]/*[local-name()="Dt"]');
            $date=substr(trim((string)($dateEl??'')),0,10);
            if($date==='') throw new RuntimeException('CAMT entry has no booking/value date');

            $ntryAmtEl=$this->first($ntry,'./*[local-name()="Amt"]');
            $ntryAmount=abs((float)(string)($ntryAmtEl??0));
            $ntryCcy=(string)(($ntryAmtEl['Ccy']??null)?:'DKK');
            $ntryRef=(string)($this->first($ntry,'./*[local-name()="AcctSvcrRef"]')??'');
            if($ntryRef==='') $ntryRef=(string)($this->first($ntry,'./*[local-name()="NtryRef"]')??'');

            $txDtls=$ntry->xpath('.//*[local-name()="TxDtls"]')?:[];
            if($txDtls){
                $amounts=[];$explicit=true;$sameCurrency=true;
                foreach($txDtls as $tx){
                    $amt=$this->firstTransactionAmount($tx);
                    if($amt===null){$explicit=false;break;}
                    $ccy=(string)(($amt['Ccy']??null)?:$ntryCcy);
                    if($ccy!==$ntryCcy)$sameCurrency=false;
                    $amounts[]=abs((float)(string)$amt);
                }
                if($explicit&&$sameCurrency&&$this->amountsReconcile($amounts,$ntryAmount)){
                    foreach($txDtls as $index=>$tx){
                        $txId=$this->transactionIdentity($tx,$ntry,$index);
                        $txs[]=$this->buildFromTxDtls($tx,$date,$creditDebit,$ntryAmount,$ntryCcy,$ntryRef,$isReversal,$ntry,$statementId,$txId);
                    }
                }else{
                    $txs[]=$this->buildFromNtryOnly($ntry,$date,$creditDebit,$ntryAmount,$ntryCcy,$ntryRef,$isReversal,true,$statementId,$ntryRef!==''?$ntryRef:'entry:'.$entryIndex);
                }
            }else{
                $txs[]=$this->buildFromNtryOnly($ntry,$date,$creditDebit,$ntryAmount,$ntryCcy,$ntryRef,$isReversal,false,$statementId,$ntryRef!==''?$ntryRef:'entry:'.$entryIndex);
            }
        }
        return $txs;
    }

    private function extractStatementId($root): string {
        foreach(['//*[local-name()="Stmt"]/*[local-name()="Id"]','//*[local-name()="Rpt"]/*[local-name()="Id"]','//*[local-name()="Ntfctn"]/*[local-name()="Id"]'] as $path){
            $node=$this->first($root,$path);
            if($node!==null&&trim((string)$node)!=='') return trim((string)$node);
        }
        return '';
    }

    private function transactionIdentity($tx,$ntry,int $index): string {
        foreach(['./*[local-name()="Refs"]/*[local-name()="AcctSvcrRef"]','./*[local-name()="Refs"]/*[local-name()="InstrId"]','./*[local-name()="Refs"]/*[local-name()="EndToEndId"]'] as $path){
            $v=$this->first($tx,$path);
            if($v!==null&&trim((string)$v)!=='') return trim((string)$v);
        }
        $v=$this->first($tx,'.//*[local-name()="AcctSvcrRef"]');
        if($v!==null&&trim((string)$v)!=='') return trim((string)$v);
        return trim((string)($this->first($ntry,'./*[local-name()="AcctSvcrRef"]')??$this->first($ntry,'./*[local-name()="NtryRef"]')??'')).':'.$index;
    }

    private function buildFromTxDtls($txDtls,string $date,string $creditDebit,float $ntryAmount,string $ntryCcy,string $ntryAcctSvcrRef,bool $isReversal,$ntry,string $statementId,string $transactionId): BankTransaction {
        $amtEl=$this->firstTransactionAmount($txDtls);
        $amount=$amtEl!==null?abs((float)(string)$amtEl):$ntryAmount;
        $ccy=$amtEl!==null?(string)(($amtEl['Ccy']??null)?:$ntryCcy):$ntryCcy;
        $signed=$creditDebit==='DBIT'?-abs($amount):abs($amount);
        $text=$this->collectUnstructured($txDtls);
        if($text==='')$text=trim((string)($this->first($ntry,'./*[local-name()="AddtlNtryInf"]')??''));
        $ref=$this->extractStructuredReference($txDtls);if($ref==='')$ref=$this->extractFallbackReference($text);
        $cp=$this->extractCounterparty($txDtls,$creditDebit);if($cp==='')$cp=$this->extractCounterparty($ntry,$creditDebit);
        $acct=(string)($this->first($txDtls,'.//*[local-name()="AcctSvcrRef"]')??'');if($acct==='')$acct=$ntryAcctSvcrRef;
        $e2e=(string)($this->first($txDtls,'.//*[local-name()="EndToEndId"]')??'');if($acct===''&&$e2e!=='')$acct=$e2e;
        $manual=$ref===''&&$cp==='';
        return $this->makeTx($date,$signed,$ccy,$text,$ref,$cp,$acct,$isReversal,$manual,$statementId,$transactionId);
    }

    private function buildFromNtryOnly($ntry,string $date,string $creditDebit,float $amount,string $ccy,string $acct,bool $reversal,bool $manual,string $statementId,string $transactionId): BankTransaction {
        $signed=$creditDebit==='DBIT'?-abs($amount):abs($amount);
        $text=$this->collectUnstructured($ntry);if($text==='')$text=trim((string)($this->first($ntry,'./*[local-name()="AddtlNtryInf"]')??''));
        $ref=$this->extractStructuredReference($ntry);if($ref==='')$ref=$this->extractFallbackReference($text);
        $cp=$this->extractCounterparty($ntry,$creditDebit);
        if($ref===''&&$cp==='')$manual=true;
        return $this->makeTx($date,$signed,$ccy,$text,$ref,$cp,$acct,$reversal,$manual,$statementId,$transactionId);
    }

    private function makeTx(string $date,float $amount,string $currency,string $text,string $ref,string $cp,string $acct,bool $reversal,bool $manual,string $statementId,string $transactionId): BankTransaction {
        $tx=new BankTransaction();
        $tx->date=$date;$tx->amount=$amount;$tx->currency=$currency!==''?$currency:'DKK';$tx->text=$text;$tx->reference=$ref;$tx->counterparty=$cp;$tx->acctSvcrRef=$acct;
        $tx->statementId=$statementId;$tx->transactionId=$transactionId;$tx->isReversal=$reversal;$tx->requiresManualReview=$manual;
        $tx->hash=hash('sha256',implode('|',[$statementId,$transactionId,$date,$amount,$ref,$cp,$text,$acct]));
        return $tx;
    }

    private function firstTransactionAmount($ctx){return $this->first($ctx,'./*[local-name()="Amt"]')??$this->first($ctx,'./*[local-name()="InstdAmt"]');}
    private function amountsReconcile(array $amounts,float $total): bool {$sum=0;foreach($amounts as $a)$sum+=(int)round(abs($a)*100);return $sum===(int)round(abs($total)*100);}
    private function extractStructuredReference($ctx): string {
        $v=$this->first($ctx,'.//*[local-name()="EndToEndId"]');if($v!==null&&trim((string)$v)!==''&&strtoupper(trim((string)$v))!=='NOTPROVIDED')return trim((string)$v);
        $v=$this->first($ctx,'.//*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]');if($v!==null&&trim((string)$v)!=='')return trim((string)$v);
        $v=$this->first($ctx,'.//*[local-name()="RfrdDocInf"]/*[local-name()="Nb"]');if($v!==null&&trim((string)$v)!=='')return trim((string)$v);
        return '';
    }
    private function extractFallbackReference(string $text): string{return preg_match('/\b(\d{4,15})\b/',$text,$m)?$m[1]:'';}
    private function extractCounterparty($ctx,string $direction): string {
        $party=$direction==='DBIT'?'Cdtr':'Dbtr';
        $v=$this->first($ctx,'.//*[local-name()="'.$party.'"]/*[local-name()="Nm"]');if($v!==null&&trim((string)$v)!=='')return trim((string)$v);
        $v=$this->first($ctx,'.//*[local-name()="RltdPties"]/*[local-name()="'.$party.'"]/*[local-name()="Nm"]');if($v!==null&&trim((string)$v)!=='')return trim((string)$v);
        $v=$this->first($ctx,'.//*[local-name()="RltdPties"]//*[local-name()="Nm"]');return $v!==null?trim((string)$v):'';
    }
    private function collectUnstructured($ctx): string{$parts=[];foreach($ctx->xpath('.//*[local-name()="Ustrd"]')?:[] as $u){$v=trim((string)$u);if($v!=='')$parts[]=$v;}return trim(implode(' ',$parts));}
    private function first($ctx,string $xpath){$nodes=@$ctx->xpath($xpath);return !empty($nodes)?$nodes[0]:null;}
}
