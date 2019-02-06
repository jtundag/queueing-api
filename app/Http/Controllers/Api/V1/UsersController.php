<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use App\Repositories\TransactionRepository;
use League\Csv\Reader;

class UsersController extends Controller
{
    private $userRepository;
    private $transactionRepository;

    public function __construct(UserRepository $userRepository,
    TransactionRepository $transactionRepository){
        $this->userRepository = $userRepository;
        $this->transactionRepository = $transactionRepository;
    }
    
    public function get(Request $request){
        return response()->json($this->userRepository->forTable($request));
    }

    public function create(Request $request){
        $request->merge(['password' => \Hash::make($request->password)]);
        $user = $this->userRepository
                        ->create($request->all());
                        
        \Bouncer::assign($request->role)->to($user);
        
        return response()->json([
            'status' => $user ? true : false
        ]);
    }
    
    public function delete(Request $request){
        $deleted = $this->userRepository
            ->deleteById($request->id);

        return response()->json([
            'status' => $deleted ? true : false,
        ]);
    }

    public function find(Request $request){
        $user = $this->userRepository
                    ->findById($request->id);
        $user['department'] = $user->department;
                    
        return response()->json([
            'status' => $user ? true : false,
            'user' => $user,
        ]);
    }

    public function update(Request $request){
        if($request->has('password')) $request->merge(['password' => \Hash::make($request->password)]);
        
        $updated = $this->userRepository
                        ->updateById($request->except('id'), $request->id);
        
        return response()->json([
            'status' => $updated ? true : false,
        ]);
    }

    public function requestVerification(Request $request){
        $authyApi = new \Authy\AuthyApi(env('TWILIO_AUTHY_API'));

        $user = $this->userRepository
                    ->where('uuid', '=', $request->uuid)
                    ->where('email', '=', $request->email)
                    ->first();
        
        $sent = $authyApi->phoneVerificationStart($user->mobile_no, '+63', 'sms');

        return response()->json([
            'status' => $sent->ok(),
            'message' => $sent->ok() ? 'Success!' : $sent->errors()->message,
        ]);
    }
    
    public function verify(Request $request){
        $user = $this->userRepository
                    ->unverifiedUser($request);

        if(!$user) return response()->json(['status' => false, 'message' => 'Cannot find an unverified account with an ID Number of ' . $request->uuid .  '.']);
        $authyApi = new \Authy\AuthyApi(env('TWILIO_AUTHY_API'));


        $verification = $authyApi->phoneVerificationCheck($user->mobile_no, '+63', $request->code);

        if(!$verification->ok()) return response()->json([
            'status' => false,
            'message' => $verification->errors()->message,
        ]);
        
        $updated = $this->userRepository
                    ->updateById([
                        'verified_at' => \Carbon\Carbon::now(),
                    ], $user->id);

        return response()->json([
            'status' => $updated ? true : false,
        ]);
    }

    public function changePassword(Request $request){
        $user = $this->userRepository
                    ->findBy('uuid', $request->uuid);
        $user->password = \Hash::make($request->password);
        $updated = $user->save();

        return response()->json([
            'status' => $updated,
        ]);
    }

    public function queues(Request $request){
        $user = auth('api')->user();

        if(!$user) return response()->json([
            'status' => false,
            'message' => 'Cannot find user.',
        ]);
        
        $transactions = $user->transactions()
            ->with('flow', 'flow.steps', 'flow.steps.department', 'flow.steps.service')
            ->whereHas('flow')
            ->whereDate('transactions.created_at', \Carbon\Carbon::today())
            ->get();

        $queues = $user->queues()
                    ->with('department')
                    ->whereDate('queues.created_at', \Carbon\Carbon::today()->toDateString())
                    ->whereIn('queues.status', ['queueing', 'skipped',]);

        $queues = $queues->get()->map(function($queue){
            $queue['waiting_time'] = $this->transactionRepository->generateWaitingTimeFor($queue);
            $queue->department['total_queues'] = $queue->department->totalQueuesForToday();
            return $queue;
        });
        
        return response()->json([
            'with_flow_transactions' => $transactions,
            'result' => $queues,
        ]);
    }

    public function availableDepartments(Request $request){
        $user = auth('api')->user();
        
        $queuedDepartments = $user->queues()
                                    ->with('department')
                                    ->whereDate('queues.created_at', \Carbon\Carbon::today()->toDateString())
                                    ->where('queues.status', '!=', 'served')
                                    ->get()
                                    ->pluck('department')
                                    ->unique()
                                    ->pluck('id')
                                    ->toArray();
                                    
        $availableDepartments = \App\Department::whereNotIn('id', $queuedDepartments)->get()
                            ->map(function($dept){
                                //$dept['total_queues'] = $dept->totalQueuesForToday();
                                $dept['total_queues'] = $dept->queues()->whereDate('queues.created_at', \Carbon\Carbon::today())->where('queues.status', '!=', 'served')->count();
                                return $dept;
                            });
        $availableDepartments->map(function($dept){
            $dept['services'] = \App\Department::find($dept->id)->servers()->with('services')->get()->pluck('services')->flatten()->unique('id');
            return $dept;
        });
        
        return response()->json([
            'result' => $availableDepartments,
        ]);
    }

    public function importCSV(Request $request){
        $csv = Reader::createFromPath($request->file('file')->getPathName(), 'r');
        $csv->setHeaderOffset(0);

        $header = $csv->getHeader();
        $records = $csv->getRecords();
        $records->rewind();
        foreach($records as $key => $value){
            $value['password'] = \Hash::make($value['password']);

            $user = $this->userRepository
                            ->create($value);

            \Bouncer::assign($request->role)->to($user);
        }
        return response()->json([
            'status' => true,
            'message' => 'Success!',
        ]);
    }
    
    public function updatePlayerId(Request $request){
        $user = auth('api')->user();
        
        if(!$user) return response()->json([
            'status' => false,
            'message' => 'Cannot find user. Please try again.',
        ]);

        $user->player_id = $request->player_id;
        $updated = $user->save();

        return response()->json([
            'status' => $updated,
            'message' => 'Success!',
            'user' => $user,
        ]);
    }
    
}
