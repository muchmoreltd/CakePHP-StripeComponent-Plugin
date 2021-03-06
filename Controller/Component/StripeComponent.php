<?php
/**
 * StripeComponent
 *
 * A component that handles payment processing using Stripe.
 *
 * PHP version 5
 *
 * @package   StripeComponent
 * @author    Gregory Gaskill <gregory@chronon.com>
 * @license   MIT License (http://www.opensource.org/licenses/mit-license.php)
 * @link    https://github.com/chronon/CakePHP-StripeComponent-Plugin
 */

App::uses('Component', 'Controller');

/**
 * StripeComponent
 *
 * @package   StripeComponent
 */
class StripeComponent extends Component {

/**
 * Default Stripe mode to use: Test or Live
 *
 * @var string
 * @access public
 */
  public $mode = 'Test';

/**
 * Default currency to use for the transaction
 *
 * @var string
 * @access public
 */
  public $currency = 'usd';

/**
 * Default mapping of fields to be returned: local_field => stripe_field
 *
 * @var array
 * @access public
 */
  public $fields = array('stripe_id' => 'id');

/**
 * The required Stripe secret API key
 *
 * @var string
 * @access public
 */
  public $key = null;

/**
 * Valid parameters that can be included in the call to Stripe_Charge::create
 *
 * @var array
 * @access protected
 */
  protected $_chargeParams = array(
    'amount',
    'currency',
    'customer',
    'card',
    'description',
    'metadata',
    'capture',
    'statement_descriptor',
    'receipt_email',
    'application_fee',
    'shipping',
    'source'
  );

/**
 * Controller startup. Loads the Stripe API library and sets options from
 * APP/Config/bootstrap.php.
 *
 * @param Controller $controller Instantiating controller
 * @return void
 * @throws CakeException
 * @throws CakeException
 */
  public function startup(Controller $controller) {
    $this->Controller = $controller;

    // load the stripe vendor class IF it hasn't been autoloaded (composer)
    App::import('vendor', 'Stripe.Stripe', array(
      'file' => 'stripe' . DS . 'stripe-php' . DS . 'lib' . DS . 'Stripe.php')
    );
    
    if (!class_exists('Stripe')) {
      throw new CakeException('Stripe API library is missing or could not be loaded.');
    }

    // if mode is set in bootstrap.php, use it. otherwise, Test.
    $mode = Configure::read('Stripe.mode');
    if ($mode) {
      $this->mode = $mode;
    }

    // set the Stripe API key
    $this->key = Configure::read('Stripe.' . $this->mode . 'Secret');
    if (!$this->key) {
      throw new CakeException('Stripe API key is not set.');
    }

    // if currency is set in bootstrap.php, use it. otherwise, usd.
    $currency = Configure::read('Stripe.currency');
    if ($currency) {
      $this->currency = $currency;
    }

    // field map for charge response, or use default (set above)
    $fields = Configure::read('Stripe.fields');
    if ($fields) {
      $this->fields = $fields;
    }
  }


  public function chargeRetrieve($id){
    Stripe::setApiKey($this->key);
    $error = null;

    try {
      $charge = Stripe_Charge::retrieve($id);
    }catch(Exception $e){
      return false;
    }

    return $charge;
  }
/**
 * The charge method prepares data for Stripe_Charge::create and attempts a
 * transaction.
 *
 * @param array $data Must contain 'amount' and 'stripeToken'.
 * @return array $charge if success, string $error if failure.
 * @throws CakeException
 * @throws CakeException
 */
  public function charge($data) {

    // $data MUST contain 'stripeToken' or 'stripeCustomer' (id) to make a charge.
    if (!isset($data['stripeToken']) && !isset($data['stripeCustomer'])) {
      throw new CakeException('The required stripeToken or stripeCustomer fields are missing.');
    }

    // if amount is missing or not numeric, abort.
    if (!isset($data['amount']) || !is_numeric($data['amount'])) {
      throw new CakeException('Amount is required and must be numeric.');
    }

    // format the amount, in cents.
    $data['amount'] = $data['amount'] * 100;

    Stripe::setApiKey($this->key);
    $error = null;

    $chargeData = array();
    foreach ($this->_chargeParams as $param) {
      if (isset($data[$param])) {
        $chargeData[$param] = $data[$param];
      }

    }

    if (!isset($chargeData['currency'])) {
      $chargeData['currency'] = $this->currency;
    }

    if (isset($data['stripeToken'])) {
      $chargeData['card'] = $data['stripeToken'];
    } else {
      $chargeData['customer'] = $data['stripeCustomer'];
    }

    try {
      //throw new Stripe_AuthenticationError("bad auth");
      $charge = Stripe_Charge::create($chargeData);

    } catch(Stripe_CardError $e) {
      $body = $e->getJsonBody();
      $err = $body['error'];
      //CakeLog::error(json_encode($body), 'stripe');
      CakeLog::error(
        'Charge::Stripe_CardError: ' . $err['type'] . ': ' . $err['code'] . ': ' . $err['message'],
        'stripe'
      );
      $error = $err['message'];

    } catch (Stripe_InvalidRequestError $e) {
      $body = $e->getJsonBody();
      $err = $body['error'];
      CakeLog::error(
        'Charge::Stripe_InvalidRequestError: ' . $err['type'] . ': ' . $err['message'],
        'stripe'
      );
      $error = $err['message'];

    } catch (Stripe_AuthenticationError $e) {
      CakeLog::error('Charge::Stripe_AuthenticationError: API key rejected!', 'stripe');
      $error = 'Payment processor API key error.';

    } catch (Stripe_ApiConnectionError $e) {
      CakeLog::error('Charge::Stripe_ApiConnectionError: Stripe could not be reached.', 'stripe');
      $error = 'Network communication with payment processor failed, try again later';

    } catch (Stripe_Error $e) {
      CakeLog::error('Charge::Stripe_Error: Stripe could be down.', 'stripe');
      $error = 'Payment processor error, try again later.';

    } catch (Exception $e) {
      CakeLog::error('Charge::Exception: Unknown error.', 'stripe');
      $error = 'There was an error, try again later.';
    }

    if ($error !== null) {
      // an error is always a string
      return (string)$error;
    }

    CakeLog::info('Stripe: charge id ' . $charge->id, 'stripe');

    return $this->_formatResult($charge);
  }

  public function planRetrieve($id){
    Stripe::setApiKey($this->key);
    $error = null;

    try {
      $plan = Stripe_Plan::retrieve($id);
    }catch(Exception $e){
      return false;
    }

    return $plan;
  }

  public function planCreate($config=array()){
    Stripe::setApiKey($this->key);
    $error = null;
    $intervals = ['month', 'week', 'day', 'year'];

    if(empty($config)){
      throw new CakeException('No plan configuration provided.');
    }

    if(empty($config['id'])) {
      throw new CakeException('Plan id is required.');
    }

    if(empty($config['amount'])) {
      throw new CakeException('Amount is required and must be numeric.');
    }

    $config['amount'] *= 100;

    if(empty($config['currency'])) {
      $config['currency'] = $this->currency;
    }

    if(empty($config['name'])) {
      $config['name'] = $config['id'];
    }

    if(empty($config['interval']) || !in_array($config['interval'], $intervals)){
      $config['interval'] = $intervals[0];
    }


    try{
      $plan = Stripe_Plan::create($config);
    }catch (Stripe_InvalidRequestError $e) {
      $body = $e->getJsonBody();
      $err = $body['error'];
      CakeLog::error(
        'Plan::Stripe_InvalidRequestError: ' . $err['type'] . ': ' . $err['message'],
        'stripe'
      );
      $error = $err['message'];
    }catch (Stripe_AuthenticationError $e) {
      CakeLog::error('Customer::Stripe_AuthenticationError: API key rejected!', 'stripe');
      $error = 'Payment processor API key error.';

    } catch (Stripe_ApiConnectionError $e) {
      CakeLog::error('Customer::Stripe_ApiConnectionError: Stripe could not be reached.', 'stripe');
      $error = 'Network communication with payment processor failed, try again later';

    } catch (Stripe_Error $e) {
      CakeLog::error('Customer::Stripe_Error: Stripe could be down.', 'stripe');
      $error = 'Payment processor error, try again later.';

    } catch (Exception $e) {
      CakeLog::error('Customer::Exception: Unknown error.', 'stripe');
      $error = 'There was an error, try again later.';
    }

    if ($error !== null) {
      // an error is always a string
      return (string)$error;
    }

    CakeLog::info('Plan: plan id ' . $plan->id, 'stripe');

    return $this->_formatResult($plan);
  }

/**
 * The customerCreate method prepares data for Stripe_Customer::create and attempts to
 * create a customer.
 *
 * @param array $data The data passed directly to Stripe's API.
 * @return array $customer if success, string $error if failure.
 */
  public function customerCreate($data) {

    // for API compatibility with version 1.x of this component
    if (isset($data['stripeToken'])) {
      $data['card'] = $data['stripeToken'];
      unset($data['stripeToken']);
    }

    Stripe::setApiKey($this->key);
    $error = null;

    try {
      //throw new Stripe_AuthenticationError("bad auth");
      $customer = Stripe_Customer::create($data);

    } catch(Stripe_CardError $e) {
      $body = $e->getJsonBody();
      $err = $body['error'];
      CakeLog::error(
        'Customer::Stripe_CardError: ' . $err['type'] . ': ' . $err['code'] . ': ' . $err['message'],
        'stripe'
      );
      $error = $err['message'];

    } catch (Stripe_InvalidRequestError $e) {
      $body = $e->getJsonBody();
      $err = $body['error'];
      CakeLog::error(
        'Customer::Stripe_InvalidRequestError: ' . $err['type'] . ': ' . $err['message'],
        'stripe'
      );
      $error = $err['message'];

    } catch (Stripe_AuthenticationError $e) {
      CakeLog::error('Customer::Stripe_AuthenticationError: API key rejected!', 'stripe');
      $error = 'Payment processor API key error.';

    } catch (Stripe_ApiConnectionError $e) {
      CakeLog::error('Customer::Stripe_ApiConnectionError: Stripe could not be reached.', 'stripe');
      $error = 'Network communication with payment processor failed, try again later';

    } catch (Stripe_Error $e) {
      CakeLog::error('Customer::Stripe_Error: Stripe could be down.', 'stripe');
      $error = 'Payment processor error, try again later.';

    } catch (Exception $e) {
      CakeLog::error('Customer::Exception: Unknown error.', 'stripe');
      $error = 'There was an error, try again later.';
    }

    if ($error !== null) {
      // an error is always a string
      return (string)$error;
    }

    CakeLog::info('Customer: customer id ' . $customer->id, 'stripe');

    return $this->_formatResult($customer);
  }

/**
 * The customerRetrieve method gets a customer object for viewing, updating, or
 * deleting.
 *
 * @param string $id Must be an existing customer id.
 * @return object $customer if success, boolean false if failure or not found.
 */
  public function customerRetrieve($id) {
    Stripe::setApiKey($this->key);
    $customer = false;

    try {
      $customer = Stripe_Customer::retrieve($id);
      //CakeLog::error($id);
    } catch (Exception $e) {
      return false;
    }
    return $customer;
  }

  /**
   * retreive envent by event id
   * $param int $id of an existing event
   */
  public function eventRetrieve($id){
    Stripe::setApiKey($this->key);
    $event = false;

    try{
      $event = Stripe_Event::retrieve($id);
    }catch(Exception $e){
      return false;
    }

    return $event;
  }

    public function subscribeToShortTrialPlan($customer_id){
        Stripe::setApiKey($this->key);

        $customer = $this->customerRetrieve($customer_id);
        $subscription = $customer->subscriptions->create(array('plan' => 'short_trial_plan_100', 'trial_end' => strtotime('+1 minute')));
        //$subscription = $customer->subscriptions->retrieve('sub_9sKIznpLlUkxTo');
        return  $this->_formatResult($subscription);
    }
/**
 * Returns an array of fields we want from Stripe's response objects
 *
 *
 * @param object $response A successful response object from this component.
 * @return array The desired fields from the response object as an array.
 */
  protected function _formatResult($response) {
    $result = array();
    foreach ($this->fields as $local => $stripe) {
      if (is_array($stripe)) {
        foreach ($stripe as $obj => $field) {
          if (isset($response->$obj->$field)) {
            $result[$local] = $response->$obj->$field;
          }
        }
      } else {
        if (isset($response->$stripe)) {
          $result[$local] = $response->$stripe;
        }
      }
    }
    // if no local fields match, return the default stripe_id
    if (empty($result)) {
      $result['stripe_id'] = $response->id;
    }
    return $result;
  }

}
