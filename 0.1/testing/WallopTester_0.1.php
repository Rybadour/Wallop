<?php
$time = microtime(true);

require_once('Wallop_0.1.test.php');

class User extends Wallop
{
	public function User($id = null)
	{
		$tableName = 'Users';
		                    
		$relations = array();
		$relations[] = array('className' => 'User', 'relationTableName' => 'UsersToFriends');
		$relations[] = array('className' => 'Message', 'relationTableName' => 'MessagesToUsers', 
		                     'dependency' => 'composite');
		$relations[] = array('className' => 'Review', 'relationTableName' => 'ReviewsToUsers');
		
		parent::Wallop($tableName, $id, $relations);
	}
}


class Message extends Wallop
{
	public function Message($id = null)
	{
		$tableName = 'Messages';
		
		$relations = array();
		$relations[] = array('className' => 'MessageType', 'relationTableName' => 'MessagesToMessageTypes',
		                     'dependency' => 'composite');
		
		// Intentionally not using $relations as a parameter
		parent::Wallop($tableName, $id, $relations);
	}
}

class MessageType extends Wallop
{
	public function MessageType($id = null)
	{
		$tableName = 'MessageTypes';
		
		$relations = array();
		$relations[] = array('className' => 'Message', 'relationTableName' => 'MessagesToMessageTypes');
		
		parent::Wallop($tableName, $id, $relations);
	}
}

class Review extends Wallop
{
	public function Review($id = null)
	{
		$tableName = 'Reviews';
		
		$relations = array();
		$relations[] = array('className' => 'User', 'relationTableName' => 'ReviewsToUsers');
		
		parent::Wallop($tableName, $id, $relations);
	}
}

/* *
$messageType = new MessageType();
$messageType->createRelational();

print_r($messageType->getErrors());
echo '<br /><br />';

/* */

$user = new User();
$user->set('username', 'rybadour');

$message = new Message();
$message->set('title', 'Test Title');
$message->set('body', 'This is a test message, it will be deleted in the end!');

$messageType = new MessageType();
$messageType->set('name', 'blah');
$messageType->set('size', 5);
$messageType->commit(false);
$message->setRelatives('MessageType', array($messageType));

$message->commit();
$user->setRelatives('Message', array($message));

$user->commit();
print_r($user);
echo '<br /><br />';

/* */

$user->remove();
$user->commit();
print_r($user);
echo '<br /><br />';

echo 'Errors:';
print_r($user->getErrors());
echo '<br /><br />';
/* */

echo 'Milliseconds Taken: '.(microtime(true) - $time) * 1000;
?>