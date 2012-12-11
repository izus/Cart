<?php
App::uses('CartAppModel', 'Cart.Model');
/**
 * Order Model
 *
 * @author Florian Krämer
 * @copyright 2012 Florian Krämer
 * @license MIT
 */
class Order extends CartAppModel {
/**
 * Behaviors
 *
 * @var array
 */
	public $actsAs = array(
		'Search.Searchable');

/**
 * Order status
 *
 * @var array
 */
	public $orderStatuses = array(
		'pending',
		'failed',
		'completed',
		'refunded',
		'partial-refunded');

/**
 * belongsTo associations
 *
 * @var array
 */
	public $belongsTo = array(
		'Cart' => array(
			'className' => 'Cart.Cart'),
		'User' => array(
			'className' => 'User'));

/**
 * belongsTo associations
 *
 * @var array
 */
	public $hasOne = array(
		'BillingAddress' => array(
			'className' => 'Cart.OrderAddress',
			'conditions' => array('type' => 'billing')),
		'ShippingAddress' => array(
			'className' => 'Cart.OrderAddress',
			'conditions' => array('type' => 'shipping')));

/**
 * hasMany associations
 *
 * @var array
 */
	public $hasMany = array(
		'OrderItem' => array(
			'className' => 'Cart.OrderItem'));

/**
 * Validation rules
 *
 * @var array
 */
	public $validate = array(
		'total' => array(
			'numeric' => array(
				'rule' => array('numeric'),
				'message' => 'This must be a number')),
		'status' => array(
			'numeric' => array(
				'rule' => array('notEmpty'),
				'message' => 'The order requires a status')),
		'processor' => array(
			'numeric' => array(
				'rule' => array('notEmpty'),
				'message' => 'The order requires a payment processor')),
		'cart_snapshot' => array(
			'notEmpty' => array(
				'rule' => array('notEmpty'),
				'message' => 'You must add the cart data to the order')));

/**
 * Filters args for search
 *
 * @var array
 */
	public $filterArgs = array(
		array('name' => 'username', 'type' => 'like', 'field' => 'User.username'),
		array('name' => 'email', 'type' => 'like', 'field' => 'User.email'),
		array('name' => 'invoice_number', 'type' => 'like'),
		array('name' => 'total', 'type' => 'value'),
		array('name' => 'created', 'type' => 'like'),
	);

/**
 * beforeSave callback
 *
 * @return boolean
 */
	public function beforeSave() {
		if (!empty($this->data[$this->alias]['cart_snapshop']) && is_array($this->data[$this->alias]['cart_snapshop'])) {
			$this->data[$this->alias]['cart_snapshop'] = serialize($this->data[$this->alias]['cart_snapshop']);
		}
		return true;
	}

/**
 * afterSave callback
 *
 * @var boolean $created
 */
	public function afterSave($created) {
		if ($created) {
			$invoiceNumber = $this->invoiceNumber();
			$this->saveField('invoice_number', $invoiceNumber);
			$this->data[$this->alias]['invoice_number'] = $invoiceNumber;
			$this->data[$this->alias][$this->primaryKey] = $this->getLastInsertId();
			CakeEventManager::dispatch(new CakeEvent('Order.created', $this, array($this->data)));
		}
	}

/**
 * afterFind callback
 *
 * @param array $results
 * @return array
 */
	public function afterFind($results, $primary) {
		$results = $this->unserializeCartSnapshot($results);
		return $results;
	}

/**
 * Unserializes the data in the cart_snapshot field when it is present
 *
 * @param array $resuls
 * @return array modified results array
 */
	public function unserializeCartSnapshot($results) {
		if (!empty($results)) {
			foreach ($results as $key => $result) {
				if (isset($result[$this->alias]['cart_snapshop'])) {
					$results[$key][$this->alias]['cart_snapshop'] = unserialize($result[$this->alias]['cart_snapshop']);
				}
			}
		}
		return $results;
	}

/**
 * Returns the data for a user to view an order he made
 *
 * @param string $orderId Order UUID
 * @param string $userId User UUId
 * @return array
 * @throws NotFoundException
 */
	public function view($orderId = null, $userId = null) {
		$order = $this->find('first', array(
			'contain' => array(
				'OrderItem'),
			'conditions' => array(
				$this->alias . '.' . $this->primaryKey => $orderId,
				$this->alias . '.user_id' => $userId)));

		if (empty($order)) {
			throw new NotFoundException(__d('cart', 'The order does not exist.'));
		}
		return $order;
	}

/**
 * Returns the data for an order for the admin
 *
 * @param string $orderId Order UUID
 * @return array
 * @throws NotFoundException
 */
	public function adminView($orderId = null) {
		$order = $this->find('first', array(
			'contain' => array(
				'User',
				'OrderItem'),
			'conditions' => array(
				$this->alias . '.' . $this->primaryKey => $orderId)));

		if (empty($order)) {
			throw new NotFoundException(__d('cart', 'The order does not exist.'));
		}
		return $order;
	}

/**
 * Validate Order
 *
 * Shipping and Billing Address validation if the cart requires shipping
 * by default true, it will get just validated and by this maybe set
 * to invalid, when the cart requires shipping
 *
 * @param 
 * @return mixed
 */
	public function validateOrder($order) {
		$validBillingAddress = true;
		$validShippingAddress = true;

		if (isset($order['Cart']['requires_shipping']) && $order['Cart']['requires_shipping'] == 1) {
			$this->ShippingAddress->set($data);
			$validShippingAddress = $this->ShippingAddress->validates();

			if (isset($order['BillingAddress']['same_as_shipping']) && $order['BillingAddress']['same_as_shipping'] == 1) {
				$order['BillingAddress'] = $order['ShippingAddress'];
			} else {
				$this->BillingAddress->set($order);
				$validBillingAddress = $this->BillingAddress->validates();
			}
		}

		$this->set($order);
		$validOrder = $this->validates();

		if (!$validOrder || !$validBillingAddress || !$validShippingAddress) {
			return false;
		}

		return $order;
	}

/**
 * This method will create a new order record and does the validation work for
 * the different cases that might apply before you can issue a new order
 *
 * @param 
 * @param 
 * @param 
 * @return mixed Array with order data on success, false if not
 * @todo finish me
 */
	public function createOrder($cartData, $processorClass, $paymentStatus = 'pending') {
		$order = array(
			$this->alias => array(
				'processor' => $processorClass,
				'payment_status' => $paymentStatus,
				'cart_id' => empty($cartData['Cart']['id']) ? null : $cartData['Cart']['id'],
				'user_id' => empty($cartData['Cart']['user_id']) ? null : $cartData['Cart']['user_id'],
				'cart_snapshop' => $cartData,
				'total' => $cartData['Cart']['total']));

		$order = Set::merge($cartData, $order);

		CakeEventManager::dispatch(new CakeEvent('Order.beforeCreateOrder', $this, array($order)));

		$order = $this->validateOrder($order);
		if ($order === false) {
			return false;
		}

		$this->create();
		$result = $this->save($order);
		$orderId = $this->getLastInsertId();
		$result[$this->alias][$this->primaryKey] = $orderId;

		foreach ($order['CartsItem'] as $item) {
			$item['order_id'] = $orderId;
			$this->OrderItem->create();
			$this->OrderItem->save($item);
		}

		if (isset($order['Cart']['requires_shipping']) && $order['Cart']['requires_shipping'] == 1) {
			$order['BillingAddress']['order_id'] = $orderId;
			$order['ShippingAddress']['order_id'] = $orderId;
			$this->BillingAddress->create();
			$this->BillingAddress->save($order);
			$this->ShippingAddress->create();
			$this->ShippingAddress->save($order);
		}

		if ($result) {
			$result[$this->alias][$this->primaryKey] = $this->getLastInsertId();
			CakeEventManager::dispatch(new CakeEvent('Order.created', $this, array($result)));
		}

		$result = Set::merge($result, unserialize($result[$this->alias]['cart_snapshop']));
		return $result;
	}

/**
 * Generates an invoice number
 *
 * @return string
 */
	public function invoiceNumber() {
		$date = date('Y-m-d');
		$count = $this->find('count', array(
				'contain' => array(),
				'conditions' => array(
						$this->alias . '.created LIKE ' => $date .'%')));
		$increment = $count + 1;
		return str_replace('-', '', $date) . '-'. $increment;
	}

}