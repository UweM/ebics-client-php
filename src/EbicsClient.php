<?php

namespace AndrewSvirin\Ebics;

use AndrewSvirin\Ebics\Contracts\EbicsClientInterface;
use AndrewSvirin\Ebics\Factories\CertificateFactory;
use AndrewSvirin\Ebics\Factories\OrderDataFactory;
use AndrewSvirin\Ebics\Factories\TransactionFactory;
use AndrewSvirin\Ebics\Handlers\OrderDataHandler;
use AndrewSvirin\Ebics\Handlers\RequestHandler;
use AndrewSvirin\Ebics\Handlers\ResponseHandler;
use AndrewSvirin\Ebics\Models\Bank;
use AndrewSvirin\Ebics\Models\KeyRing;
use AndrewSvirin\Ebics\Models\Request;
use AndrewSvirin\Ebics\Models\Response;
use AndrewSvirin\Ebics\Models\User;
use AndrewSvirin\Ebics\Services\CryptService;
use DateTime;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * EBICS client representation.
 *
 * @license http://www.opensource.org/licenses/mit-license.html  MIT License
 * @author Andrew Svirin
 */
final class EbicsClient implements EbicsClientInterface
{

   /**
    * An EbicsBank instance.
    * @var Bank
    */
   private $bank;

   /**
    * An EbicsUser instance.
    * @var User
    */
   private $user;

   /**
    * @var KeyRing
    */
   private $keyRing;

   /**
    * @var OrderDataHandler
    */
   private $orderDataHandler;

   /**
    * @var ResponseHandler
    */
   private $responseHandler;

   /**
    * @var RequestHandler
    */
   private $requestFactory;

   /**
    * Constructor.
    * @param Bank $bank
    * @param User $user
    * @param KeyRing $keyRing
    */
   public function __construct(Bank $bank, User $user, KeyRing $keyRing)
   {
      $this->bank = $bank;
      $this->user = $user;
      $this->keyRing = $keyRing;
      $this->requestFactory = new RequestHandler($bank, $user, $keyRing);
      $this->orderDataHandler = new OrderDataHandler($bank, $user, $keyRing);
      $this->responseHandler = new ResponseHandler();
   }

   /**
    * Make request to bank server.
    * @param Request $request
    * @return ResponseInterface
    * @throws TransportExceptionInterface
    */
   public function post(Request $request): ResponseInterface
   {
      $body = $request->getContent();
      $httpClient = HttpClient::create();
      $response = $httpClient->request('POST', $this->bank->getUrl(), [
         'headers' => [
            'Content-Type' => 'text/xml; charset=ISO-8859-1',
         ],
         'body' => $body,
         'verify_peer' => false,
         'verify_host' => false,
      ]);
      return $response;
   }

   /**
    * {@inheritdoc}
    * @throws ClientExceptionInterface
    * @throws RedirectionExceptionInterface
    * @throws ServerExceptionInterface
    * @throws TransportExceptionInterface
    */
   public function HEV(): Response
   {
      $request = $this->requestFactory->buildHEV();
      $hostResponse = $this->post($request);
      $hostResponseContent = $hostResponse->getContent();
      $response = new Response();
      $response->loadXML($hostResponseContent);
      return $response;
   }

   /**
    * {@inheritdoc}
    * @throws ClientExceptionInterface
    * @throws RedirectionExceptionInterface
    * @throws ServerExceptionInterface
    * @throws TransportExceptionInterface
    */
   public function INI(DateTime $dateTime = null): Response
   {
      if (null === $dateTime)
      {
         $dateTime = DateTime::createFromFormat('U', time());
      }
      $certificateA = CertificateFactory::generateCertificateAFromKeys(CryptService::generateKeys($this->keyRing), $this->bank->isCertified());
      $request = $this->requestFactory->buildINI($certificateA, $dateTime);
      $hostResponse = $this->post($request);
      $hostResponseContent = $hostResponse->getContent();
      $response = new Response();
      $response->loadXML($hostResponseContent);
      if ('000000' === $this->responseHandler->retrieveH004ReturnCode($response))
      {
         $this->keyRing->setUserCertificateA($certificateA);
      }
      return $response;
   }

   /**
    * {@inheritdoc}
    * @throws ClientExceptionInterface
    * @throws RedirectionExceptionInterface
    * @throws ServerExceptionInterface
    * @throws TransportExceptionInterface
    */
   public function HIA(DateTime $dateTime = null): Response
   {
      if (null === $dateTime)
      {
         $dateTime = DateTime::createFromFormat('U', time());
      }
      $certificateE = CertificateFactory::generateCertificateEFromKeys(CryptService::generateKeys($this->keyRing), $this->bank->isCertified());
      $certificateX = CertificateFactory::generateCertificateXFromKeys(CryptService::generateKeys($this->keyRing), $this->bank->isCertified());
      $request = $this->requestFactory->buildHIA($certificateE, $certificateX, $dateTime);
      $hostResponse = $this->post($request);
      $hostResponseContent = $hostResponse->getContent();
      $response = new Response();
      $response->loadXML($hostResponseContent);
      if ('000000' === $this->responseHandler->retrieveH004ReturnCode($response))
      {
         $this->keyRing->setUserCertificateE($certificateE);
         $this->keyRing->setUserCertificateX($certificateX);
      }
      return $response;
   }

   /**
    * {@inheritdoc}
    * @throws ClientExceptionInterface
    * @throws RedirectionExceptionInterface
    * @throws ServerExceptionInterface
    * @throws TransportExceptionInterface
    * @throws Exceptions\EbicsException
    */
   public function HPB(DateTime $dateTime = null): Response
   {
      if (null === $dateTime)
      {
         $dateTime = DateTime::createFromFormat('U', time());
      }
      $request = $this->requestFactory->buildHPB($dateTime);
      $hostResponse = $this->post($request);
      $hostResponseContent = $hostResponse->getContent();
      $response = new Response();
      $response->loadXML($hostResponseContent);
      if ('000000' === $this->responseHandler->retrieveH004ReturnCode($response))
      {
         // Prepare decrypted OrderData.
         $orderDataEncrypted = $this->responseHandler->retrieveOrderData($response);
         $orderDataDecrypted = CryptService::decryptOrderData($this->keyRing, $orderDataEncrypted);
         $response->setDecryptedOrderData($orderDataDecrypted);
         $orderData = OrderDataFactory::buildOrderDataFromContent($orderDataDecrypted->getOrderData());
         $response->addTransaction(TransactionFactory::buildTransactionFromOrderData($orderData));
         $certificateX = $this->orderDataHandler->retrieveAuthenticationCertificate($orderData);
         $certificateE = $this->orderDataHandler->retrieveEncryptionCertificate($orderData);
         $this->keyRing->setBankCertificateX($certificateX);
         $this->keyRing->setBankCertificateE($certificateE);
      }
      return $response;
   }

   /**
    * {@inheritdoc}
    * @throws ClientExceptionInterface
    * @throws RedirectionExceptionInterface
    * @throws ServerExceptionInterface
    * @throws TransportExceptionInterface
    * @throws Exceptions\EbicsException
    */
   public function HPD(DateTime $dateTime = null): Response
   {
      if (null === $dateTime)
      {
         $dateTime = DateTime::createFromFormat('U', time());
      }
      $request = $this->requestFactory->buildHPD($dateTime);
      $hostResponse = $this->post($request);
      $hostResponseContent = $hostResponse->getContent();
      $response = new Response();
      $response->loadXML($hostResponseContent);
      if ('000000' === $this->responseHandler->retrieveH004ReturnCode($response))
      {
         // TODO: Send Receipt transaction.
         $transaction = $this->responseHandler->retrieveTransaction($response);
         $response->addTransaction($transaction);
         // Prepare decrypted OrderData.
         $orderDataEncrypted = $this->responseHandler->retrieveOrderData($response);
         $orderDataDecrypted = CryptService::decryptOrderData($this->keyRing, $orderDataEncrypted);
         $response->setDecryptedOrderData($orderDataDecrypted);
         $orderData = OrderDataFactory::buildOrderDataFromContent($orderDataDecrypted->getOrderData());
         $transaction->setOrderData($orderData);
      }
      return $response;
   }

   /**
    * {@inheritdoc}
    * @throws ClientExceptionInterface
    * @throws RedirectionExceptionInterface
    * @throws ServerExceptionInterface
    * @throws TransportExceptionInterface
    * @throws Exceptions\EbicsException
    */
   public function HAA(DateTime $dateTime = null): Response
   {
      if (null === $dateTime)
      {
         $dateTime = DateTime::createFromFormat('U', time());
      }
      $request = $this->requestFactory->buildHAA($dateTime);
      $hostResponse = $this->post($request);
      $hostResponseContent = $hostResponse->getContent();
      $response = new Response();
      $response->loadXML($hostResponseContent);
      return $response;
   }

   /**
    * {@inheritdoc}
    * @throws ClientExceptionInterface
    * @throws RedirectionExceptionInterface
    * @throws ServerExceptionInterface
    * @throws TransportExceptionInterface
    * @throws Exceptions\EbicsException
    */
   public function VMK(DateTime $dateTime = null, DateTime $startDateTime = null, DateTime $endDateTime = null): Response
   {
      if (null === $dateTime)
      {
         $dateTime = DateTime::createFromFormat('U', time());
      }
      $request = $this->requestFactory->buildVMK($dateTime, $startDateTime, $endDateTime);
      $hostResponse = $this->post($request);
      $hostResponseContent = $hostResponse->getContent();
      $response = new Response();
      $response->loadXML($hostResponseContent);
      return $response;
   }

   /**
    * {@inheritdoc}
    * @throws ClientExceptionInterface
    * @throws RedirectionExceptionInterface
    * @throws ServerExceptionInterface
    * @throws TransportExceptionInterface
    * @throws Exceptions\EbicsException
    */
   public function STA(DateTime $dateTime = null, DateTime $startDateTime = null, DateTime $endDateTime = null): Response
   {
      if (null === $dateTime)
      {
         $dateTime = DateTime::createFromFormat('U', time());
      }
      $request = $this->requestFactory->buildSTA($dateTime, $startDateTime, $endDateTime);
      $hostResponse = $this->post($request);
      $hostResponseContent = $hostResponse->getContent();
      $response = new Response();
      $response->loadXML($hostResponseContent);
      if ('000000' === $this->responseHandler->retrieveH004ReturnCode($response))
      {
          $orderDataEncrypted = $this->responseHandler->retrieveOrderData($response);
          $response->setDecryptedOrderData(CryptService::decryptOrderData($this->keyRing, $orderDataEncrypted));
      }
      return $response;
   }

}
