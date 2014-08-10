<?php

class UploadController extends \BaseController {

	protected $awsSecretAccessKey = 'TSTVgBB8SlIffH8s8xwJWaQqM1nNTZGZC2p081Vr';
	protected $awsAccessKeyId = 'AKIAJQHYOKA5YCOWQSAQ';
	protected $bucket = 'phanime';

	public function sign($directory)
	{
		$inputs = Input::all();


		$s3 = new Aws_S3_PostPolicy($this->awsAccessKeyId, $this->awsSecretAccessKey, $this->bucket, 86400);

		// If image category exists use it,
		// else make cover as the default category
		if (array_key_exists('imageCategory', $inputs)) {
			$imageCategory = $inputs['imageCategory'];
		} else {
			$imageCategory = 'cover';
		}

		// If namePref is given as an input, use it instead
		if (array_key_exists('namePref', $inputs)) {

			// For user related things we don't want the extensions
			if ($directory === 'users') {
				$imageName = $inputs['namePref'];
			} else {
				$imageName = $inputs['namePref'] . $s3->getFileExt($inputs['type']);
			}

			
		} else {
			$imageName = $inputs['name'];
		}


		$s3->addCondition('', 'bucket', $s3->getBucket());
		$s3->addCondition('', 'acl', 'public-read');
		$s3->addCondition('', 'success_action_status', '201');
		$s3->addCondition('starts-with', '$key', 'images/' . $directory . "/" .  $imageCategory ."/");
		$s3->addCondition('starts-with', '$content-type', '');

		return Response::json(array(
			"acl" => "public-read",
			"awsaccesskeyid" => $s3->getAwsAccessKeyId(),
			"bucket" => $s3->getBucket(),

			"key" => "images/" . $directory . "/" .  $imageCategory ."/" . $imageName,
			"policy" => $s3->getPolicy(true),
			"signature" => $s3->getSignedPolicy(),
			"success_action_status" => "201",
			'Content-Type' => $inputs['type'],
		), 200);
	}

	public function upload() {
		//$inputs = Input::all();

		$s3 = AWS::get('s3');

		try {
			$result = $s3->putObject(array(
				'Bucket'     => 'phanime',
				'Key'        => 'images/characters/' . Input::file('file')->getClientOriginalName(),
				'SourceFile' => Input::file('file')->getRealPath(),
				'ContentType' => Input::file('file')->getMimeType(),
				'ACL'          => 'public-read',
			));

			Log::info(print_r($result, true));	
		} catch (S3Exception $e) {
			Log::info(print_r($e->getMessage(), true));
		}	
	}
}

class Aws_S3_PostPolicy {

	/**
	 * AWS Secret Access Key.
	 *
	 * @var string
	 */
	protected $_awsSecretAccessKey = 'TSTVgBB8SlIffH8s8xwJWaQqM1nNTZGZC2p081Vr';
	
	/**
	 * AWS Access Key ID.
	 *
	 * @var string
	 */
	protected $_awsAccessKeyId = 'AKIAJQHYOKA5YCOWQSAQ';
	
	/**
	 * Amazon S3 bucket.
	 *
	 * @var string
	 */
	protected $_bucket = 'phanime';
	
	/**
	 * Array of conditions.
	 *
	 * @var array
	 */
	protected $_conditions = array();    
	
	/**
	 * Duration in seconds that the policy is valid.  Default is 24 hours.
	 *
	 * @var int
	 */
	protected $_duration = 86400;
	
	/**
	 * Maintain a cache of un-encoded policy so that multiple requests to get 
	 * the policy will not incur unnecessary computation.
	 * 
	 * @var string
	 */
	protected $_policyCache = '';
		
	/**
	 * Construct a new POST policy object.
	 *
	 * @param string $awsSecretAccessKey
	 *      Your AWS Secret Access Key.
	 * 
	 * @param string $bucket
	 *      Amazon S3 bucket name.
	 *
	 * @param int $duration
	 *      The number of seconds for which the policy is valid.
	 */
	public function __construct($awsAccessKeyId, $awsSecretAccessKey, $bucket = '', $duration = 86400) {      
		$this->_awsAccessKeyId = $awsAccessKeyId;
		$this->_awsSecretAccessKey = $awsSecretAccessKey;
		$this->_bucket = $bucket;
		$this->_duration = $duration;
		$this->_expireCache();
	}
	
	/**
	 * Add a policy condition.
	 *
	 * @param string $condition
	 *      Condition type.  Possible values are 'eq', 'starts-with', 
	 *      and 'content-length-range'.  Pass null or an empty string to set a 
	 *      condition that uses the {"field": "match"} syntax.  Passing anything
	 *      in the $condition parameter will cause the condition to use the 
	 *      [condition, field, match] syntax.
	 *
	 * @param string $field
	 *      The field name.
	 *
	 * @param string $match
	 *      String to match.
	 *
	 * @return Aws_S3_PostPolicy
	 *      Returns a reference to the object.
	 *
	 * <code>
	 * $policy->addCondition('', 'acl', 'public-read'); 
	 * // Produces: {"acl": "public-read"}
	 *
	 * $policy->addCondition('eq', 'acl', 'public-read'); 
	 * // Produces: ["eq", "$acl", "public-read"]
	 *
	 * $policy->addCondition('starts-with', '$key', 'user/betty/');
	 * // Produces: ["starts-with", "$key", "user/betty/"]
	 *
	 * $policy->addCondition('content-length-range', 1048579, 10485760);
	 * // Produces: ["content-length-range", 1048579, 10485760]
	 * </code>
	 */
	public function addCondition($condition = 'eq', $field, $match = '') {
		$this->_conditions[] = array($condition, $field, $match);
		$this->_expireCache();
		return $this;
	}
	
	/**
	 * Get the AWS Access Key ID associated with this POST policy.
	 *
	 * @return string
	 *      Returns the AWS Access Key ID.
	 *
	 */
	public function getAwsAccessKeyId() {
		return $this->_awsAccessKeyId;
	}
	
	/**
	 * Get the bucket associated with the policy.
	 *
	 * @return string
	 *      Returns the Amazon S3 bucket name.
	 */
	public function getBucket() {
		return $this->_bucket;
	}


	/**
	* Get Duration associated with the policy
	*
	* @return int
	*		Returns the duration 
	*/
	public function getDuration() {
		return $this->_duration;
	}
	/**
	 * Get a spefic permission based on the field name.
	 *
	 * @param string $field
	 *      Field name to retrieve.
	 *
	 * @param bool $fullCondition
	 *      Whether or not to return the entire condition as an array or just 
	 *      retrieve the match value of the condition.  By default, this is set 
	 *      to false so that it will only retrieve the match value of the 
	 *      condition.
	 *
	 * @return array|string
	 *      If $fullCondition is set to true, then this method will return an 
	 *      indexed array of data about the condition: 
	 *      array(condition, field, match).
	 *      Returns an empty array if the field is not found.
	 *
	 *      If $fullCondition is set to false, then this method will only return the
	 *      match field of the condition.
	 */
	public function getCondition($field, $fullCondition = false) {
		if (count($this->_conditions)) {
			foreach ($this->_conditions as $condition) {
				if ($condition[1] === $field) {
					return ($fullCondition) ? $condition : $condition[2];
				}
			}
		}
		return ($fullCondition) ? array() : null;
	}
	
	/**
	 * Get all of the conditions associated with the policy.
	 *
	 * @return array
	 *      Returns an indexed array of conditions.
	 */
	public function getConditions() {
		return $this->_conditions;
	}
	
	/**
	 * Retrieve the POST policy.
	 *
	 * @param bool $encode
	 *      Set to true to retrieve the encoded policy.
	 *
	 * @return string
	 *      Returns an un-signed POST policy.
	 */
	public function getPolicy($encode = true) {
		if (!$this->_policyCache) {
			$policy = sprintf('{ "expiration": "%s"', gmdate('Y-n-d\TH:i:s.000\Z', time() + $this->_duration));
			if (count($this->_conditions)) {
				$policy .= ', "conditions": [';
				$first = true;
				foreach ($this->_conditions as $condition) {                
					if (!$first) {
						$policy .= ', ';
					}
					if ($condition[0]) {
						$policy .= sprintf('["%s", "%s", "%s"]', $condition[0], $condition[1], $condition[2]);
					} else {
						$policy .= sprintf('{"%s": "%s"}', $condition[1], $condition[2]);
					}
					$first = false;
				}
				$policy .= ']';
			}
			$policy .= '}';
			$this->_policyCache = $policy;
		} else {
			$policy = $this->_policyCache;
		}
		if (!$encode) {
			return $policy;
		} else {
			return base64_encode(utf8_encode(preg_replace('/\s\s+|\\f|\\n|\\r|\\t|\\v/', '', $policy)));
		}
	}
	
	/**
	 * Get a signed POST policy.
	 *
	 * @return string
	 *      Returns a signed POST policy.
	 */
	public function getSignedPolicy() {        
		return base64_encode(hash_hmac('sha1', $this->getPolicy(true), $this->_awsSecretAccessKey, true));
	}
	
	/**
	 * Expire the policy cache.
	 *
	 * @return Aws_S3_Policy
	 *      Returns a reference to the object.
	 */
	protected function _expireCache() {
		$this->_policyCache = '';
		return $this;
	}
	
	/**
	 * Reset the policy by clearing the conditions, removing the bucket name, 
	 * and making the duration 86400 seconds (24 hours).
	 *
	 * @return Aws_S3_PostPolicy
	 *      Returns a reference to the object.
	 */    
	public function reset() {
		$this->_conditions = array();
		$this->_bucket = '';
		$this->_duration = 0;
		$this->_expireCache();
		return $this;
	}
	
	/**
	 * Set the Amazon S3 bucket.
	 *
	 * @param string $bucket
	 *      Amazon S3 bucket name.
	 *
	 * @return Aws_S3_PostPolicy
	 *      Returns a reference to the object.
	 */
	public function setBucket($bucket) {
		$this->_bucket = $bucket;
		return $this;
	}
	
	/**
	 * Set the number of seconds the policy is valid.
	 *
	 * @param int $seconds
	 *      The number of seconds the policy should be valid.
	 *
	 * @return Aws_S3_PostPolicy
	 *      Returns a reference to the object.
	 */
	public function setDuration($seconds) {
		$this->_duration = $seconds;
		$this->_expireCache();
		return $this;
	}
	
	// public function policy() {
	// 	base64_encode(
	// 		array(
	// 			"expiration" => "2015-03-10T20:05:20.054Z"
	// 			"conditions" => array(
	// 				array( "bucket" => 'phanime' ),
	// 				array( "acl" => 'public-read' ),
	// 				array( "expires" => '2015-03-10T20:05:20.054Z'),
	// 				array( "success_action_status" => '201'),
	// 				array( 'starts-with', )





	// 			)



	// 		)
	// 	);
	
	// }

	public function url_safe_base64_encode($value) {
		$encoded = base64_encode($value);
		// replace unsafe characters +, = and / with 
		// the safe characters -, _ and ~
		return str_replace(
			array('+', '=', '/'),
			array('-', '_', '~'),
			$encoded);		
	}


	public function getFileExt($contentType) {
		$map = array(
			'application/pdf'   => '.pdf',
			'application/zip'   => '.zip',
			'image/gif'         => '.gif',
			'image/jpeg'        => '.jpg',
			'image/png'         => '.png',
			'text/css'          => '.css',
			'text/html'         => '.html',
			'text/javascript'   => '.js',
			'text/plain'        => '.txt',
			'text/xml'          => '.xml',
		);
		if (isset($map[$contentType]))
		{
			return $map[$contentType];
		}

		// Just incase the content type was not found
		// in the map
		$pieces = explode('/', $contentType);
		return '.' . array_pop($pieces);		
	}

}
