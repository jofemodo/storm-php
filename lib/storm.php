<?php

interface iShellBolt {}
interface iShellSpout {}

class Tuple
{
	public $id, $component, $stream, $task, $values;

	public function __construct($id, $component, $stream, $task, $values)
	{
		$this->id = $id;
		$this->component = $component;
		$this->stream = $stream;
		$this->task = $task;
		$this->values = $values;
	}
}

class BoltProcessException extends Exception {}

abstract class ShellComponent
{
	protected $pid;
	protected $stormConf;
	protected $topologyContext;
	
	protected $stormInc = null;
	protected $_DEBUG = false;
	
	public function __construct($debug = false)
	{		
		$this->pid = getmypid();
		$this->sendCommand(array( "pid" => $this->pid ));
		
		$this->_debug = $debug;
		
		if ($this->_DEBUG)
		{
			$this->stormInc = fopen('/tmp/' . $this->pid . "_" . strtolower($_SERVER['argv'][0]) . '.txt', 'w+');
		}
		
		$handshake = $this->parseMessage( $this->waitForMessage() );
		
		$this->stormConf = $handshake['conf'];
		$this->topologyContext = $handshake['context'];
		$pidDir = $handshake['pidDir'];
		
		@fclose(@fopen($pidDir . "/" . $this->pid, "w"));
	}
	
	protected function readLine()
	{
		$line = trim(fgets(STDIN));
		
		if ($this->_DEBUG)
		{
			fputs($this->stormInc, $line . "\n");
		}
		
		return $line;
	}
	
	protected function waitForMessage()
	{
		$message = '';
		while (true)
		{
			$line = trim($this->readLine());
			
			if (strlen($line) == 0)
			{
				continue;
			}
			else if ($line == 'end')
			{
				break;
			}
			else if ($line == 'sync')
			{
				$message = '';
				continue;
			}
			
			$message .= $line . "\n";
		}
		return trim($message);
	}
	
	protected function sendCommand(array $command)
	{
		$this->sendMessage(json_encode($command));
	}
	
	protected function sendLog($message)
	{
		return $this->sendCommand(array(
			'command' => 'log',
			'msg' => $message
		));
	}
	
	protected function parseMessage($message)
	{
		$msg = json_decode($message, true);
		
		if ($msg)
		{
			return $msg;
		}
		else
		{
			return $message;
		}
	}
	
	protected function sendMessage($message)
	{
		echo $message . "\n";
		echo "end\n";
		fflush(STDOUT);
	}

	protected function sendSync()
	{
		$command = array(
			'command' => 'sync'
		);
		$this->sendCommand($command);
		//$this->sendLog("[".__METHOD__."]: SYNC");
	}

	protected function sendAck(Tuple $tuple)
	{
		$command = array(
			'command' => 'ack',
			'id' => $tuple->id
		);
		$this->sendCommand($command);
		//$this->sendLog("[".__METHOD__."]: ".$tuple->id);
	}

	protected function sendFail(Tuple $tuple)
	{
		$command = array(
			'command' => 'fail',
			'id' => $tuple->id
		);
		$this->sendCommand($command);
		//$this->sendLog("[".__METHOD__."]: ".$tuple->id);
	}

}

abstract class ShellBolt extends ShellComponent implements iShellBolt {

	public $anchor_tuple = null;
	
	public function __construct()
	{
		parent::__construct();
		
		$this->init($this->stormConf, $this->topologyContext);
	}
	
	public function run()
	{
		try {
			while(true)
			{
				$command = $this->parseMessage( $this->waitForMessage() );
								
				if (is_array($command))
				{
					if (isset($command['tuple']))
					{
						$tupleMap = array_merge(array(
							'id' => null, 
							'comp' => null, 
							'stream' => null, 
							'task' => null, 
							'tuple' => null
						),
						$command);
		
						$tuple = new Tuple($tupleMap['id'], $tupleMap['comp'], $tupleMap['stream'], $tupleMap['task'], $tupleMap['tuple']);
						$this->process($tuple);
					}
				}
			}
		} 
		catch(Exception $e)
		{
			$this->sendLog( $e->getTraceAsSTring() );
		}
	}
	
	abstract protected function process(Tuple $tuple);
	
	protected function init($conf, $topology)
	{
		return;
	}

	protected function emitTuple(array $tuple, $stream = null, $anchors = array(), $directTask = null)
	{
		if ($this->anchor_tuple !== null)
		{
			$anchors = array($this->anchor_tuple);
		}

		$command = array(
			'command' => 'emit'
		);
		
		if($stream !== null)
		{
			$command['stream'] = $stream;
		}

		$command['anchors'] = array_map(function($a) {
			return $a->id;
		}, $anchors);

		if($directTask !== null)
		{
			$command['task'] = $directTask;
		}

		$command['tuple'] = $tuple;

		$this->sendCommand($command);
	}

	protected function emit(array $tuple, $stream = null, $anchors = array())
	{
		$this->emitTuple($tuple, $stream, $anchors);
	}

	protected function emitDirect($directTask, array $tuple, $stream = null, $anchors = array())
	{
		$this->emitTuple($tuple, $stream, $anchors, $directTask);
	}
}

abstract class BasicBolt extends ShellBolt
{
	public function run()
	{
		try {
			while(true)
			{
				$command = $this->parseMessage( $this->waitForMessage() );
				
				if (is_array($command))
				{
					if (isset($command['tuple']))
					{
						$tupleMap = array_merge(array(
							'id' => null, 
							'comp' => null, 
							'stream' => null, 
							'task' => null, 
							'tuple' => null
						),
						$command);
						
						$tuple = new Tuple($tupleMap['id'], $tupleMap['comp'], $tupleMap['stream'], $tupleMap['task'], $tupleMap['tuple']);
						$this->anchor_tuple = $tuple;
						
						try
						{
							$processed = $this->process($tuple);
							$this->sendAck($tuple);
						}
						catch (BoltProcessException $e)
						{
							$this->sendFail($tuple);
						}
					}
				}
			}
		} 
		catch(Exception $e)
		{
			$this->sendLog($e->getTraceAsSTring());
		}
		
	}
}

abstract class ShellSpout extends ShellComponent implements iShellSpout
{
	protected $tuples = array();
	
	public function __construct()
	{
		parent::__construct();
		
		$this->init($this->stormConf, $this->topologyContext);
	}
	
	abstract protected function nextTuple();
	abstract protected function ack($tuple_id);
	abstract protected function fail($tuple_id);
	
	public function run()
	{
		while (true)
		{
			$command = $this->parseMessage( $this->waitForMessage() );
			
			if (is_array($command))
			{
				if (isset($command['command']))
				{
					if ($command['command'] == 'ack')
					{
						$this->ack($command['id']);
					}
					else if ($command['command'] == 'fail')
					{
						$this->fail($command['id']);
					}
					else if ($command['command'] == 'next')
					{
						$this->nextTuple();
					}
				}
				$this->sendSync();
			}
		}
	}
	
	protected function init($stormConf, $topologyContext)
	{
		return;
	}
	
	final private function emitTuple(array $tuple, $messageId = null, $streamId = null, $directTask = null)
	{
		$command = array(
			'command' => 'emit'
		);
		
		if ($messageId !== null)
		{
			$command['id'] = $messageId;
		}
		
		if ($streamId !== null)
		{
			$command['stream'] = $streamId;
		}

		if ($directTask !== null)
		{
			$command['task'] = $directTask;
		}

		$command['tuple'] = $tuple;

		return $this->sendCommand($command);
	}

	final protected function emit(array $tuple, $messageId = null, $streamId = null)
	{
		return $this->emitTuple($tuple, $messageId, $streamId, null);
	}
	
	final protected function emitDirect($directTask, array $tuple, $messageId = null, $streamId = null)
	{
		return $this->emitTuple($tuple, $messageId, $streamId, $directTask);
	}
}
