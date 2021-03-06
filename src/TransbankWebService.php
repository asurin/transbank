<?php
namespace Freshwork\Transbank;


use Freshwork\Transbank\Exceptions\InvalidCertificateException;
use Freshwork\Transbank\Log\LogHandler;
use Freshwork\Transbank\WebpayOneClick\WebpayOneClickWebService;
use SoapValidation;
use Freshwork\Transbank\Log\LoggerInterface;
use Model_Transbank_TransactionDA;
use Model_Transbank_TransactionTools;
use Utils_Entorno;

/**
 * Class TransbankWebService
 * @package Freshwork\Transbank
 */
abstract class TransbankWebService
{
    /**
     * @var TransbankSoap
     */
    protected $soapClient;

    /**
     * @var CertificationBag
     */
    protected $certificationBag;

    /**
     * @var
     */
    protected static $classmap = [];

	/**
	 * WebpayOneClick constructor.
	 * @param CertificationBag $certificationBag
	 * @param string $url
	 * @param LoggerInterface $logger
	 */
    function __construct(CertificationBag $certificationBag, $url = null)
    {
        $url = $this->getWsdlUrl($certificationBag, $url);

        $this->certificationBag = $certificationBag;

        $this->soapClient = new TransbankSoap($url, [
            "classmap" => static::$classmap,
            "trace" => true,
            "exceptions" => true
        ]);

        $this->soapClient->setCertificate($this->certificationBag->getClientCertificate());
        $this->soapClient->setPrivateKey($this->certificationBag->getClientPrivateKey());
    }

    /**
     * @return CertificationBag
     */
    public function getCertificationBag()
    {
        return $this->certificationBag;
    }

    /**
     * @param CertificationBag $certificationBag
     */
    public function setCertificationBag(CertificationBag $certificationBag)
    {
        $this->certificationBag = $certificationBag;
    }

	/**
     * @return TransbankSoap
     */
    public function getSoapClient()
    {
        return $this->soapClient;
    }

    /**
     * @throws InvalidCertificateException
     */
    public function validateResponseCertificate($buyOrder = '', $finalUrl = '', $method = '')
    {
        $xmlResponse = $this->getLastRawResponse();

        $soapValidation = new SoapValidation($xmlResponse, $this->certificationBag->getServerCertificate());
        $validation =  $soapValidation->getValidationResult(); //Esto valida si el mensaje está firmado por Transbank

        // Si $validation no es TRUE enviamos Exception.
        if ($validation !== true)
        {
        	$msg = 'The Transbank response fails on the certificate signature validation. Response doesn\t comes from Transbank';
        	LogHandler::log($msg, LoggerInterface::LEVEL_ERROR);

            Model_Transbank_TransactionDA::addLogResponseTransbank(Utils_Entorno::getCurrentIdEmpresa(), $buyOrder, 'Validate Response Certificate', $msg);

            // Si la verificación es dek metodo OneClick forzamos ruta de error.
            if ($method == 'authorize' || $method == 'initInscription') {
                echo RedirectorHelper::redirectBackOneClick();
            } else {
                echo RedirectorHelper::redirectBackNormal($finalUrl, Model_Transbank_TransactionTools::TOKEN_ERROR, 'token_ws');
            }

            //throw new InvalidCertificateException($msg);
        }
    }

    /**
     * @param $method
     * @return mixed
     * @throws InvalidCertificateException
     */
    protected function callSoapMethod($method)
    {
        //Get arguments, and remove the first one ($method) so the $args array will just have the additional paramenters
        $args = func_get_args();
        array_shift($args);

	    LogHandler::log($args, LoggerInterface::LEVEL_INFO, 'request_object');

	    try{
		    //Call $this->getSoapClient()->$method($args[0], $arg[1]...)
		    $response = call_user_func_array([$this->getSoapClient(), $method], $args);
		    LogHandler::log($response, LoggerInterface::LEVEL_INFO, 'response_object');
	    } catch (\SoapFault $e) {
		    LogHandler::log('SOAP ERROR (' . $e->faultcode . '): ' . $e->getMessage(), LoggerInterface::LEVEL_ERROR, 'error');
            $log = Utils_Entorno::getCurrentIdEmpresa() . '-' . 'SOAP ERROR (' . $e->faultcode . '): ' . $e->getMessage() . ' ' . LoggerInterface::LEVEL_ERROR . ' ' . 'error - ' . serialize($args). ' ' . 'detailed - (' . ($e->faultstring) ." - " . $e->detail." - " . $e->faultname .")";
            Model_Transbank_TransactionDA::addLogResponseTransbank(Utils_Entorno::getCurrentIdEmpresa(), $args[0]->wsInitTransactionInput->transactionDetails[0]->buyOrder, $method, $log);

            if ($method == 'authorize' || $method == 'initInscription') {
                echo RedirectorHelper::redirectBackOneClick();
            } else {
                echo RedirectorHelper::redirectBackNormal($args[0]->wsInitTransactionInput->finalURL, Model_Transbank_TransactionTools::TOKEN_ERROR, 'token_ws');
            }

            //throw new \SoapFault($e->faultcode, $e->faultstring);
	    }

	    //Validate the signature of the response
        $this->validateResponseCertificate($args[0]->wsInitTransactionInput->transactionDetails[0]->buyOrder, $args[0]->wsInitTransactionInput->finalURL, $method);

	    LogHandler::log("Response certificate validated successfully", LoggerInterface::LEVEL_INFO, 'response_certificate_validated');

        return $response;
    }

    /**
     * This method allows you to call any method on the SoapClient
     * @param $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, array $arguments)
    {
        array_unshift($arguments, $name);
        return call_user_func_array([$this, 'callSoapMethod'], $arguments);
    }

    /**
     * @return string
     */
    protected function getLastRawResponse()
    {
        $xmlResponse = $this->getSoapClient()->__getLastResponse();
        return $xmlResponse;
    }

    /**
     * @param CertificationBag $certificationBag
     * @param $url
     * @return string
     */
    public function getWsdlUrl(CertificationBag $certificationBag, $url = null)
    {
        if ($url) return $url;

        if ($certificationBag->getEnvironment() == CertificationBag::PRODUCTION) {
            return static::PRODUCTION_WSDL;
        }

        return static::INTEGRATION_WSDL;
    }
}