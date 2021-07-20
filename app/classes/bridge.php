<?php
class Bridge{
    //Define F3 instance
    var $f3;

    //Define Database instance
    var $db;
    
    //Define RestCord instance
    var $discord;

    //Define Monolog instance
    var $logger;
    
    //Define last message sequence number
    var $last_msg;
    
    //Define loop
    var $loop;

    //Set up handles
    function __construct($f3){
        $this->f3 = $f3;
        $this->db = $f3->get('dbh');
        $this->logger = new ColorLog("bridge.log");
        $this->discord = new RestCord\DiscordClient(['token' => $f3->get('discord_token'), 'logger' => $this->logger->log_err, 'throwOnRatelimit' => false]);
        $this->last_msg = false;
        $this->loop = false;
    }

    //Initalize Database connection
    function init_db(){
        //Set database options
        $db_options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_PERSISTENT => TRUE,
            \PDO::MYSQL_ATTR_COMPRESS => TRUE,
        ];
        
        //Init database handle
        $db = new DB\SQL(sprintf('mysql:host=%s;dbname=%s', $this->f3->get('db_host'), $this->f3->get('db_name')), $this->f3->get('db_user'), $this->f3->get('db_pass'), $db_options);
        
        //Clear old handle if set and add new one
        $this->f3->clear('dbh');
        $this->f3->set('dbh', $db);
        $this->logger->log_debug("[Database] Re-initalized database connection.");
    }

    //Process tags in queue
    function run_bridge(){
        //Create Eventloop
        $this->loop = React\EventLoop\Factory::create();

        //Set timer to run every 10 minutes
        $timer = $this->loop->addPeriodicTimer(600, function () {
            //Re-init database connection
            $this->init_db();
        });

        //Set timer to run in 1 second
        $this->loop->addTimer(1, function(){
            //Check for new messages from the database
            $this->check_for_msgs();
        });

        //Start the EventLoop
        $this->loop->run();
    }
    
    function format_message($msg){
        //Format timestamp
        $date = date('H:i:s', strtotime($msg['date']));
        
        //Check for valid matched Steam2 ID
        if(!empty($msg['steamid'])){
            //Save Steam2 ID
            $steamid = $msg['steamid'];
            
            try{
                //Convert Steam2 ID to Steam64 ID
                $conv = new SteamID($steamid);
                $steam_id = $conv->ConvertToUInt64();
            }catch(Exception $e){
                //Invalid Steam2 ID
                $steam_id = false;
            }
        }

        //Check if we have channel for serverid
        if(!isset($this->f3->get('servers')[$msg['srvid']]['channel_id'])){
            $this->logger->log_err("[Format Message] Error finding channel ID for server ID #".$msg['srvid']);
        }else{
            $this->logger->log_debug("[Format Message] Processing message ID #".$msg['srvid']);
            
            //Loop through channel IDs
            foreach($this->f3->get('servers')[$msg['srvid']]['channel_id'] as $channel){
                //Set channel ID and chat ID
                $channel_id = $channel;
                $chat_id = $msg['seqid'];
                
                //Format message
                $this->build_message($msg, $chat_id, $channel_id, $date, $steam_id);
            }
        }
    }

    function build_message($msg, $chat_id, $channel_id, $date, $steam_id){
        //Build message
        switch ($msg['type']){
            //Notification
            case -1:
                //Build message
                $message = [
                    'channel.id' => $channel_id,
                    'embed' => [
                        'author' => [
                            'name' => "[{$date}]"
                        ],
                        'description' => $msg['text'],
                        'color'       => 16763904,
                        'timestamp'   => false
                    ]
                ];
                break;
                
            //Regular chat
            case 0:
                //Build message
                $message = [
                    'channel.id' => $channel_id,
                    'embed' => [
                        'author' => [
                            'name' => "[{$date}] {$msg['name']}",
                            'url'  => "http://steamcommunity.com/profiles/{$steam_id}"
                        ],
                        'description' => (string)$msg['text'],
                        'timestamp'   => false
                    ]
                ];
                break;
                
            //Dead Chat
            case 1:
                //Build message
                $message = [
                    'channel.id' => $channel_id,
                    'embed' => [
                        'author' => [
                            'name' => "[{$date}] [DEAD] {$msg['name']}",
                            'url'  => "http://steamcommunity.com/profiles/{$steam_id}"
                        ],
                        'description' => (string)$msg['text'],
                        'timestamp'   => false
                    ]
                ];
                break;
                
            //Team Chat
            case 2:
                //Build message
                $message = [
                    'channel.id' => $channel_id,
                    'embed' => [
                        'author' => [
                            'name' => "[{$date}] [TEAM] {$msg['name']}",
                            'url'  => "http://steamcommunity.com/profiles/{$steam_id}"
                        ],
                        'description' => (string)$msg['text'],
                        'timestamp'   => false
                    ]
                ];
                break;

            //Team Chat
            case 3:
                //Build message
                $message = [
                    'channel.id' => $channel_id,
                    'embed' => [
                        'author' => [
                            'name' => "[{$date}] [DEAD] [TEAM] {$msg['name']}",
                            'url'  => "http://steamcommunity.com/profiles/{$steam_id}"
                        ],
                        'description' => (string)$msg['text'],
                        'timestamp'   => false
                    ]
                ];
                break;
        
            //All other types
            default:
                $this->logger->log_debug("[Build Message] Ignored message type #{$msg['type']}");
                $message = false;
                break;
        }

        //Check if message was built
        if($message){
            //Send message to discord
            $this->send_message($message, $chat_id, $channel_id);
        }else{
            //No message was built
            $this->logger->log_debug("[Build Message] Unable to build message for chat message #{$chat_id}");
        }
    }

    function send_message($message, $chat_id, $channel_id){
        try{
            //Attempt to send message to Discord channel
            $this->discord->channel->createMessage($message);
            $this->logger->log_debug("[Send Discord Message] Sent message #{$chat_id} to channel ID: {$channel_id}");
        }catch(Exception $e){
            //Error sending message
            $this->logger->log_err("[Send Discord Message] Error sending message #{$chat_id} to channel ID: {$e->getMessage()}.");
        }
    }
   
    //Function to check for chat messages and push them to each channel
    function check_for_msgs(){
        //Check if we have a point to start at for messages
        if(!$this->last_msg){
            $this->logger->log_info("[Chat Check] Message start point empty, starting from last message in database.");
            try{
                //Message start point empty, pull from database
                $start = $this->db->exec('SELECT seqid FROM '.$this->f3->get('db_table').' ORDER BY seqid DESC LIMIT 1');
            }catch(Exception $e){
                //Error getting start point from database
                $this->logger->log_err("[Chat Check] Error getting message start point from database!");
            }
            
            //Check for message start point
            if(count($start) !== 0){
                //Set last message id
                $this->last_msg = $start[0]['seqid'] - 1;
            }
        }
        
        try{
            //Check for any new messages since seqid
            $new_messages = $this->db->exec('SELECT seqid,srvid,date,name,steamid,text,type FROM '.$this->f3->get('db_table').' WHERE seqid > ?',array(1=>$this->last_msg));
        }catch(Exception $e){
            //Error getting messages from database
            $this->logger->log_err("[Chat Check] Error getting new messages from database.");
        }
        
        //Check if there is at least one new message
        if(count($new_messages) == 0){
            //No new messages, nothing to process
            $this->logger->log_debug("[Chat Check] No new messages.");
        }else{
            //Loop through messages
            foreach ($new_messages as $message){
                $this->format_message($message);
                
                //Save seqid to determine last
                $last = $message['seqid'];
            }
            //Save the last seqid to cache
            $this->last_msg = $last;
        }
        
        //Set to run again
        $this->loop->addTimer(1, function() use ($logger) {
            $this->check_for_msgs();
        });
    }
}