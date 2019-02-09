<?php

namespace App\Repositories;

use App\Repositories\Repository;
use Illuminate\Container\Container as App;
use App\Repositories\DepartmentRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\PredefinedFlowsRepository;

class TransactionRepository extends Repository{

	protected $departmentRepo;
	protected $serviceRepo;
	protected $flowRepo;

	public function __construct(App $app, 
								DepartmentRepository $departmentRepo,
								ServiceRepository $serviceRepo,
								PredefinedFlowsRepository $flowRepo){
		parent::__construct($app);
		$this->departmentRepo = $departmentRepo;
		$this->serviceRepo = $serviceRepo;
		$this->flowRepo = $flowRepo;
    }
	
	public function model(){
		return '\App\Transaction';
	}

	private function hasPendingQueueIn($request, $user){
		$queued = $user->queues()
				->where('queues.department_id', $request->department_id)
				->where('queues.service_id', $request->service_id)
				->whereDate('queues.created_at', \Carbon\Carbon::today())
				->whereIn('queues.status', ['queueing', 'skipped'])
				->limit(1)
				->count();
		return $queued;
	}
	
	public function push($request, $user){
		if($this->hasPendingQueueIn($request, $user)) return response()->json(['status' => false, 'message' => 'Only 1 queue is allowed per department.']);
		$transaction = $this->create([
			'user_id' => $user->id, 
			'status' => 'processing',
		]);
		// If the transaction is custom
		if(!$request->has('flow_id')){
			$queue = $this->createQueueFor($transaction, [
				'department_id' => $request->department_id,
				'service_id' => $request->service_id,
			]);
			if(!$queue) return response()->json(['status' => false, 'message' => 'Cannot create queue.']);
			$waitingTime = $this->generateWaitingTimeFor($queue);
			
			return response()->json(['status' => true, 'priority_number' => $queue->priority_number, 'waiting_time' => $waitingTime]);
		}
		$transaction->flow()->create([
			'flow_id' => $request->flow_id,
		]);
		$flow = $this->flowRepo->findById($request->flow_id);
		$transaction->flow
				->steps()
				->attach($flow->steps);
		$firstStep = $transaction->flow->steps->first();
		$queue = $this->createQueueFor($transaction, [
			'department_id' => $firstStep->department->id,
			'service_id' => $firstStep->service->id,
		]);
		$firstStep->pivot->status = 'processing';
		$firstStep->pivot->save();
		$waitingTime = $this->generateWaitingTimeFor($queue);

		if($request->has('guest') && $request->guest && $queue->transaction->user->mobile_no){
			$deviceID = env('SMSGATEWAYME_DEVICE_ID', '');
			$number = $queue->transaction->user->mobile_no;
			$message = 'Your priority number is ' . $queue->priority_number . '.';
			
			$config = Configuration::getDefaultConfiguration();
			$config->setApiKey('Authorization', env('SMSGATEWAYME_API', ''));
			$apiClient = new ApiClient($config);
			$messageClient = new MessageApi($apiClient);

			$sendMessageRequest = new SendMessageRequest([
				'phoneNumber' => $number,
				'message' => $message,
				'deviceId' => $deviceID,
			]);

			$sendMessages = $messageClient->sendMessages([
				$sendMessageRequest,
			]);
		}
		
		return response()->json(['status' => true, 'priority_number' => $queue->priority_number, 'waiting_time' => $waitingTime,]);
	}

	public function generateWaitingTimeFor($queue){
		$avgDuration = \App\Service::join('server_service', 'services.id', '=', 'server_service.service_id')
			->join('servers', 'server_service.server_id', '=', 'servers.id')
			->where('servers.department_id', $queue->department->id)
			->where('services.id', $queue->service->id)
			->avg('server_service.duration');

		$queuesCount =\App\Queue::where('service_id', $queue->service->id)
							->where('department_id', $queue->department->id)
							->whereDate('created_at', $queue->created_at->toDateString())
							->whereTime('created_at', '<=', $queue->created_at->toTimeString())
							->whereIn('status', ['skipped', 'queueing'])
							->count();

		return ($queuesCount - 1) * $avgDuration;
	}

	private function generateNumberFor($department){
		$countToday = $department->queues()
							->whereDate('queues.created_at', '=', \Carbon\Carbon::today())
							->count();
		return $department->prefix . str_pad(($countToday + 1), 4, '0', STR_PAD_LEFT);
	}
	
	private function saveQueues($transaction, $service, $priorityNumber, $initialStatus = 'queueing'){
		$entryData = [
			$service->id => [
				'priority_number' => $priorityNumber,
				'status' => $initialStatus,
				'created_at' => \Carbon\Carbon::now(),
			],
		];
			
		return $transaction->queues()->attach($entryData);
	}

	public function createQueueFor($transaction, $data){
		$department = $this->departmentRepo->findById($data['department_id']);
		$service = $this->serviceRepo->findById($data['service_id']);
		$priorityNumber = $this->generateNumberFor($department);
		$data['priority_number'] = $priorityNumber;
		$queue = $transaction->queues()->create($data);
		return $queue;
	}
	
	public function findQueueById($id){
		return \App\Queue::findOrFail($id);
	}
	
}