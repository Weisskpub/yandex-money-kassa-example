<?php
namespace shop;

use HttpException;

require_once "../Log.php";
require_once "../Utils.php";

/**
 * Implementation of Merchant Web Services protocol.
 */
class MWS {

    private $settings;
    private $log;

    function __construct(Settings $settings) {
        $this->log = new Log($settings);
        $this->settings = $settings;
    }

    /**
     * Returns a successful orders and their properties.
     * @return string answer from Yandex.Money in XML format
     */
    public function listOrders() {
        $action = "listOrders";
        $this->log->info("Start " . $action);
        $dateTime = Utils::formatDateForMWS(new \DateTime());
        $requestParams = array(
            'requestDT' => $dateTime,
            'outputFormat' => 'XML',
            'shopId' => $this->settings->SHOP_ID,
            'orderCreatedDatetimeLessOrEqual' => $dateTime
        );
        $result = $this->sendUrlEncodedRequest($action, $requestParams);
        $this->log->info($result);
        return $result;
    }

    /**
     * Returns refunded payments.
     * @return string answer from Yandex.Money in XML format
     */
    public function listReturns() {
        $action = "listReturns";
        $this->log->info("Start " . $action);
        $dateTime = Utils::formatDateForMWS(new \DateTime()) ;
        $requestParams = array(
            'requestDT' => $dateTime,
            'outputFormat' => 'XML',
            'shopId' => $this->settings->SHOP_ID,
            'from' => '2015-01-01T00:00:00.000Z',
            'till' => $dateTime
        );
        $result = $this->sendUrlEncodedRequest($action, $requestParams);
        $this->log->info($result);
        return $result;
    }

    /**
     * Refunds a successful transfer to the Payer's account.
     * @param  string|int $invoiceId transaction number of the transfer being refunded
     * @param  string $amount        amount to refund to the Payer's account
     * @return string                answer from Yandex.Money in XML format
     */
    public function returnPayment($invoiceId, $amount) {
        $action = "returnPayment";
        $this->log->info("Start " . $action);
        $dateTime = Utils::formatDate(new \DateTime()) ;
        $requestParams = array(
            'clientOrderId' => mktime(),
            'requestDT' => $dateTime,
            'invoiceId' => $invoiceId,
            'shopId' => $this->settings->SHOP_ID,
            'amount' => number_format($amount, 2),
            'currency' => $this->settings->CURRENCY,
            'cause' => 'Нет товара'
        );
        $result = $this->sendXmlRequest($action, $requestParams);
        $this->log->info($result);
        return $result;
    }

    /**
     * Completes a successful transfer to the merchant's account. Used for deferred transfers.
     * @param  string|int $orderId transaction number of the transfer being confirmed
     * @param  string     $amount  amount to transfer
     * @return string              answer from Yandex.Money in XML format
     */
    public function confirmPayment($orderId, $amount) {
        $action = "confirmPayment";
        $this->log->info("Start " . $action);
        $dateTime = Utils::formatDate(new \DateTime()) ;
        $requestParams = array(
            'clientOrderId' => mktime(),
            'requestDT' => $dateTime,
            'orderId' => $orderId,
            'amount' => $amount,
            'currency' => 'RUB'
        );
        $result = $this->sendUrlEncodedRequest($action, $requestParams);
        $this->log->info($result);
        return $result;
    }

    /**
     * Cancels a deferred payment.
     * @param  string|int $orderId transaction number of the deferred payment
     * @return string              answer from Yandex.Money in XML format
     */
    public function cancelPayment($orderId) {
        $action = "cancelPayment";
        $this->log->info("Start " . $action);
        $dateTime = Utils::formatDate(new \DateTime()) ;
        $requestParams = array(
            'requestDT' => $dateTime,
            'orderId' => $orderId
        );
        $result = $this->sendUrlEncodedRequest($action, $requestParams);
        $this->log->info($result);
        return $result;
    }

    /**
     * Repeats a payment using the Payer's card data (with the Payer's consent) to pay for the store's
     * products or services.
     * @param  string|int $invoiceId transaction number of the transfer being repeated.
     * @param  string $amount        amount to make the payment
     * @return string                answer from Yandex.Money in XML format
     */
    public function repeatCardPayment($invoiceId, $amount) {
        $action = "repeatCardPayment";
        $this->log->info("Start " . $action);
        $requestParams = array(
            'clientOrderId' => mktime(),
            'invoiceId' => $invoiceId,
            'amount' => $amount
        );
        $result = $this->sendUrlEncodedRequest($action, $requestParams);
        $this->log->info($result);
        return $result;
    }

    private function signData($data) {
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
        );
        $descriptorspec[2] = $descriptorspec[1];
        try {
            $opensslCommand = 'openssl smime -sign -signer ' . $this->settings->mws_cert .
                ' -inkey ' . $this->settings->mws_private_key .
                ' -nochain -nocerts -outform PEM -nodetach -passin pass:'.$this->settings->mws_cert_password;
            $this->log->info("opensslCommand: " . $opensslCommand);
            $process = proc_open($opensslCommand, $descriptorspec, $pipes);
            if (is_resource($process)) {
                fwrite($pipes[0], $data);
                fclose($pipes[0]);
                $pkcs7 = stream_get_contents($pipes[1]);
                $this->log->info($pkcs7);
                fclose($pipes[1]);
                $resCode = proc_close($process);
                if ($resCode != 0) {
                    $errorMsg = 'OpenSSL call failed:' . $resCode . '\n' . $pkcs7;
                    $this->log->info($errorMsg);
                    throw new \Exception($errorMsg);
                }
                return $pkcs7;
            }
        } catch (\Exception $e) {
            $this->log->info($e);
            throw $e;
        }
    }

    private function sendXmlRequest($action, $data) {
        $body = '<?xml version="1.0" encoding="UTF-8"?>';
        $body .= '<' . $action . 'Request ';
        foreach($data AS $param => $value) {
            $body .= $param . '="' . $value . '" ';
        }
        $body .= '/>';

        return $this->sendRequest($action, $this->signData($body), "pkcs7-mime");
    }

    private function sendUrlEncodedRequest($paymentMethod, $data) {
        return $this->sendRequest($paymentMethod, http_build_query($data), "x-www-form-urlencoded");
    }

    private function sendRequest($action, $requestBody, $contentType) {
        $this->log->info($action . " Request: " . $requestBody);
  
        $curl = curl_init();
        $params = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER => array('Content-type: application/' . $contentType),
            CURLOPT_URL => 'https://penelope-demo.yamoney.ru:8083/webservice/mws/api/' . $action,
            CURLOPT_POST => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSLCERT => $this->settings->mws_cert,
            CURLOPT_SSLKEY => $this->settings->mws_private_key,
            CURLOPT_SSLCERTPASSWD => $this->settings->mws_cert_password,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_VERBOSE => 1,
            CURLOPT_POSTFIELDS => $requestBody
        );
        curl_setopt_array($curl, $params);
        $result = null;
        try {
            $result = curl_exec($curl);
            if (!$result) {
                trigger_error(curl_error($curl));
            }
            curl_close($curl);
        } catch (HttpException $ex) {
            echo $ex;
        }
        return $result;
    }
}
