<?php
/**
 * This file is part of RemesasSEPAAnticipos plugin for FacturaScripts.
 * FacturaScripts       Copyright (C) 2015-2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 * RemesasSEPA          Copyright (C) 2019-2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 * RemesasSEPAAnticipos Copyright (C) 2019-2024 Jose Antonio Cuello Principal <yopli2000@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\RemesasSEPAAnticipos\Lib;

use DateTime;
use Digitick\Sepa\Exception\InvalidArgumentException;
use Digitick\Sepa\GroupHeader;
use Digitick\Sepa\PaymentInformation;
use Digitick\Sepa\TransferFile\Factory\TransferFileFacadeFactory;
use Exception;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Accounting\CalculateSwift;
use FacturaScripts\Dinamic\Model\RemesaSEPA;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 */
class RemesaPagosCli
{
    /**
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public static function getXML(RemesaSEPA $remesa): string
    {
        $header = new GroupHeader(date('Y-m-d-H-i-s'), self::sanitizeName($remesa->getNombre()));
        $header->setInitiatingPartyId($remesa->creditorid);
        $directDebit = TransferFileFacadeFactory::createDirectDebitWithGroupHeader($header, 'pain.008.001.02');

        // añadimos los pagos en la cuenta de la empresa
        $bankAccount = $remesa->getBankAccount();
        $bankIban = $bankAccount->getIban();
        foreach (self::getGroupedReceipts($remesa) as $collectionDate => $collection) {
            // añadimos la cabecera del pago
            $paymentId = date('YmdHis-') . $collectionDate;
            $paymentInfo = [
                'id' => $paymentId,
                'dueDate' => new DateTime($collectionDate),
                'creditorName' => self::sanitizeName($remesa->getCompany()->nombre),
                'creditorAccountIBAN' => $bankIban,
                'creditorAgentBIC' => CalculateSwift::getSwift($bankIban, $bankAccount->swift),
                'seqType' => PaymentInformation::S_RECURRING,
                'creditorId' => $remesa->creditorid,
                'localInstrumentCode' => $remesa->tipo
            ];
            if (empty($paymentInfo['creditorAgentBIC'])) {
                unset($paymentInfo['creditorAgentBIC']);
            }
            $directDebit->addPaymentInfo($paymentId, $paymentInfo);

            // añadimos los cobros de los recibos
            foreach ($collection as $item) {
                $transfer = [
                    'amount' => $item['amount'],
                    'debtorIban' => str_replace(' ', '', $item['debtorIban']),
                    'debtorBic' => $item['debtorBic'],
                    'debtorName' => $item['debtorName'],
                    'debtorMandate' => $item['debtorMandate'],
                    'debtorMandateSignDate' => $item['debtorMandateSignDate'],
                    'remittanceInformation' => Tools::lang()->trans('invoice') . ' ' . implode(', ', $item['remittanceInformation']),
                    'endToEndId' => end($item['endToEndId'])
                ];
                if (empty($transfer['debtorBic'])) {
                    unset($transfer['debtorBic']);
                }
                $directDebit->addTransfer($paymentId, $transfer);
            }
        }

        return $directDebit->asXML();
    }

    /**
     * @throws Exception
     */
    protected static function getGroupedReceipts(RemesaSEPA $remittance): array
    {
        // Obtenemos la fecha de cargo de la remesa
        $cargoDate = date('Y-m-d', strtotime($remittance->fechacargo));

        // Agrupamos los recibos por fecha de vencimiento.
        // Si la fecha de cargo es posterior a la de vencimiento, se agrupan por fecha de cargo.
        $items = [];
        foreach ($remittance->getReceipts() as $receipt) {
            $bankAccount = $receipt->getBankAccount();
            $fmandato = date('Y-m-d', strtotime($bankAccount->fmandato));
            $invoice = $receipt->getInvoice();
            $dueDate = date('Y-m-d', strtotime($receipt->vencimiento));

            // Obtenemos la fecha donde agrupar el recibo.
            $collectionDate = ($cargoDate >= $dueDate) ? $cargoDate : $dueDate;

            if (!$remittance->agrupar) {
                $items[$collectionDate][] = [
                    'amount' => $receipt->importe,
                    'debtorIban' => $receipt->iban,
                    'debtorBic' => $receipt->swift,
                    'debtorName' => self::sanitizeName($receipt->getSubject()->razonsocial),
                    'debtorMandate' => $bankAccount->primaryColumnValue(),
                    'debtorMandateSignDate' => new DateTime($fmandato),
                    'remittanceInformation' => [$invoice->codigo . '-' . $receipt->numero],
                    'endToEndId' => [$invoice->codigo . '-' . $receipt->numero]
                ];
                continue;
            }

            // Si no existe el nivel de agrupación, lo inicializamos
            if (!isset($items[$collectionDate][$receipt->codcliente])) {
                $items[$collectionDate][$receipt->codcliente] = [
                    'amount' => 0,
                    'debtorIban' => $receipt->iban,
                    'debtorBic' => $receipt->swift,
                    'debtorName' => self::sanitizeName($receipt->getSubject()->razonsocial),
                    'debtorMandate' => $bankAccount->primaryColumnValue(),
                    'debtorMandateSignDate' => new DateTime($fmandato)
                ];
            }

            // Añadimos el recibo al nivel de agrupación
            $items[$collectionDate][$receipt->codcliente]['amount'] += $receipt->importe;
            $items[$collectionDate][$receipt->codcliente]['remittanceInformation'][] = $invoice->codigo . '-' . $receipt->numero;
            $items[$collectionDate][$receipt->codcliente]['endToEndId'][] = $invoice->codigo . '-' . $receipt->numero;
        }

        return $items;
    }

    protected static function sanitizeName(string $name): string
    {
        $changes = ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'd',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ő' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ű' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',
            '&' => '&amp;', 'À' => 'A', 'Á' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I',
            'Í' => 'I', 'Ò' => 'O', 'Ó' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Ü' => 'U',
            'Ñ' => 'N', 'Ç' => 'C'
        ];

        $newName = str_replace(array_keys($changes), $changes, $name);
        return substr($newName, 0, 70);
    }
}
