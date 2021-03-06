<?php

namespace Drupal\om_tbc_payments\Services;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\File\FileSystem;
use Drupal\Core\KeyValueStore\KeyValueFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\language\ConfigurableLanguageManager;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use WeAreDe\TbcPay\TbcPayProcessor;

/**
 * Service for TBC Payments.
 */
class PaymentService {
  /**
   * EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * LoggerFactory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;


  /**
   * Language.
   *
   * @var \Drupal\language\ConfigurableLanguageManager
   */
  protected $languageManager;


  /**
   * Language.
   *
   * @var \WeAreDe\TbcPay\TbcPayProcessor
   */
  protected $tbcPayProcessor;

  /**
   * Request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * FileSystem.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * KeyValue.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueFactory
   */
  protected $keyValue;

  /**
   * Current User.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * Dotenv.
   *
   * @var \Symfony\Component\Dotenv\Dotenv
   */
  protected $dotEnv;

  /**
   * Constructor for service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity Type Manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Logger.
   * @param \Drupal\language\ConfigurableLanguageManager $languageManager
   *   LanguageManager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Request.
   * @param \Drupal\Core\File\FileSystem $fileSystem
   *   FileSystem.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactory $keyValue
   *   KeyValue storage.
   * @param \Drupal\Core\Session\AccountProxy $currentUser
   *   CurrentUser.
   * @param \Drupal\Core\Extension\ModuleHandler $moduleHandler
   *   ModuleHandler.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager,
                              LoggerChannelFactoryInterface $loggerFactory,
                              ConfigurableLanguageManager $languageManager,
                              RequestStack $request_stack,
                              FileSystem $fileSystem,
                              KeyValueFactory $keyValue,
                              AccountProxy $currentUser,
                              ModuleHandler $moduleHandler
  ) {
    $modulePath = $moduleHandler->getModule('om_tbc_payments')->getPath();

    // Load certificate path and pass.
    $this->dotEnv = new Dotenv();
    $this->dotEnv->load($modulePath . '/cert/.cert.env');

    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $loggerFactory;
    $this->languageManager = $languageManager;
    $this->request = $request_stack->getCurrentRequest();
    $this->fileSystem = $fileSystem;
    $this->keyValue = $keyValue;
    $this->currentUser = $currentUser;
    $this->tbcPayProcessor = new TbcPayProcessor(
      $_ENV['CERT_PATH'],
      $_ENV['CERT_PASS'],
      $this->request->getClientIp()
    );
  }

  /**
   * Function for adding payment records.
   *
   * @param string $trans_id
   *   Transaction ID.
   * @param array $payment_data
   *   Payment Data.
   *
   * @return bool
   *   bool.
   */
  public function addPaymentRecord($trans_id, array $payment_data) {
    if (!$trans_id && strlen($trans_id) !== 28) {
      $this->loggerFactory
        ->get('om_tbc_payments')
        ->error('Failed to save payment.');

      return FALSE;
    }
    else {
      try {
        $values = [
          'trans_id' => $trans_id,
          'user_id' => $this->currentUser->id(),
          'amount' => $payment_data['amount'] * 100,
          'ip_address' => $this->request->getClientIp(),
          'currency_code' => 981,
          'description' => $payment_data['description'],
        ];
        $payment = $this->entityTypeManager
          ->getStorage('payment')
          ->create($values);
        $payment->save();
        $this->loggerFactory->get('om_tbc_payments')->info('Payment was saved with status INITIAL.');

        return TRUE;
      }
      catch (InvalidPluginDefinitionException $e) {

        $this->loggerFactory
          ->get('om_tbc_payments')
          ->error($e->getMessage());

      }
      catch (PluginNotFoundException $e) {

        $this->loggerFactory
          ->get('om_tbc_payments')
          ->error($e->getMessage());

      }
      catch (EntityStorageException $e) {

        $this->loggerFactory
          ->get('om_tbc_payments')
          ->error($e->getMessage());

      }
    }

    return FALSE;
  }

  /**
   * Wrapper function to create transaction ID.
   *
   * @param int $amount
   *   Amount of GEL.
   * @param string $description
   *   Description for transaction.
   *
   * @return string
   *   String with transaction id.
   */
  public function generateTransactionId($amount, $description) {

    $this->tbcPayProcessor->amount = $amount * 100;
    $this->tbcPayProcessor->currency = 981;
    $this->tbcPayProcessor->description = $description;
    $this->tbcPayProcessor->language = $this->getLanguage();
    $id = $this->tbcPayProcessor->sms_start_transaction()['TRANSACTION_ID'];
    if ($id) {
      $this->loggerFactory->get('om_tbc_payments')->info('Transaction ID was generated.');
      $this->addPaymentRecord($id, ['amount' => $amount, 'description' => $description]);
      return $id;
    }
    else {
      $this->loggerFactory->get('om_tbc_payments')->error('Error generating transaction ID in payment service.');
      return NULL;
    }
  }

  /**
   * Wrapper function to save card.
   *
   * @param int $amount
   *   Amount of GEL.
   * @param string $description
   *   Description for transaction.
   *
   * @return array
   *   Array with transaction and card id.
   */
  public function saveCard($amount, $description) {
    $card_id = $this->generateCardCode();
    $this->tbcPayProcessor->amount = $amount * 100;
    $this->tbcPayProcessor->currency = 981;
    $this->tbcPayProcessor->description = $description;
    $this->tbcPayProcessor->language = $this->getLanguage();
    $this->tbcPayProcessor->biller_id = $card_id;
    $response = $this->tbcPayProcessor->save_card();
    if (isset($response['TRANSACTION_ID']) && $card_id) {
      $this->loggerFactory->get('om_tbc_payments')->info('Transaction id for saving card was generated.');
      return [
        'transaction_id' => $response['TRANSACTION_ID'],
        'card_id' => $card_id,
      ];
    }
    else {
      $this->loggerFactory->get('om_tbc_payments')->error('Error while generating transaction ID for card save in payment service.');
      return NULL;
    }

  }

  /**
   * Make redirect to ufc.
   *
   * @param string $id
   *   TransactionID.
   *
   * @return mixed
   *   Redirect.
   */
  public function makePayment($id) {
    try {
      // $id = $this->generateTransactionId($amount, $description);
      if (!$id || strlen($id) !== 28) {
        $this->loggerFactory->get('om_tbc_payments')->error('Error creating transaction ID.');
        return new Response('Transaction ID is missing', Response::HTTP_BAD_REQUEST);
      }
      $key = $this->getString();
      $this->keyValue->get('om_tbc_payments')->set($key, $id);
      $redirect = new RedirectResponse("/donate/prepare?key=$key");
      $redirect->send();

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('om_tbc_payments')->error($e->getMessage());
    }

    return FALSE;
  }

  /**
   * Wrapper function for executing payment.
   *
   * @param string $card_id
   *   Saved card id.
   * @param int $amount
   *   Amount of money.
   * @param string $description
   *   Description.
   *
   * @return array
   *   Response from TBC.
   */
  public function executePayment($card_id, $amount, $description) {
    $this->tbcPayProcessor->biller_id = $card_id;
    $this->tbcPayProcessor->amount = $amount * 100;
    $this->tbcPayProcessor->currency = 981;
    $this->tbcPayProcessor->description = 'Regular payment';
    $response = $this->tbcPayProcessor->execute_transaction();
    if (empty($response)) {
      $this->loggerFactory->get('om_tbc_payments')->error('Error executing payment.');
      return NULL;
    }
    else {
      $transaction_id = $response['TRANSACTION_ID'];
      $result = $response['RESULT_CODE'];
      $result_code = $response['RESULT_CODE'];
      $this->addPaymentRecord($transaction_id, ['amount' => $amount, 'description' => $description]);
      $this->loggerFactory->get('om_tbc_payments')->info(sprintf('Payment was executed. Status: %s, Card id: %s', $result, $card_id));
      return [
        'transaction_id' => $transaction_id,
        'result'         => $result,
        'code'           => $result_code,
      ];
    }
  }

  /**
   * Function for close day for TBC.
   */
  public function closeDay() {
    $response = $this->tbcPayProcessor->close_day();
    $this->loggerFactory->get('om_tbc_payments')->info("Day was closed !");
    return Json::encode($response);
  }

  /**
   * Function for getting result of payment.
   *
   * @param string $id
   *   Transaction ID.
   *
   * @return array
   *   array.
   */
  public function getPaymentResult($id) {
    try {
      $result = $this->tbcPayProcessor->get_transaction_result($id);
      return $result;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('om_tbc_payments')->error("Can't get result of payment.");
    }

    return [];
  }

  /**
   * Random string.
   *
   * @return string
   *   string
   */
  private function getString() {
    return uniqid();
  }

  /**
   * Random string for card code.
   *
   * @return string
   *   card code.
   */
  private function generateCardCode() {
    return md5(uniqid(10, TRUE));
  }

  /**
   * Function for getting language.
   *
   * @return string
   *   Language string.
   */
  public function getLanguage() {
    if ($this->languageManager->getCurrentLanguage()->getId() === 'ka') {
      return "GE";
    }
    else {
      return "EN";
    }
  }

}
