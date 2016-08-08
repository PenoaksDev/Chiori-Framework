<?php namespace Milky\Account\Permissions;

use Milky\Binding\UniversalBuilder;
use Milky\Helpers\Arr;
use Milky\Helpers\Str;
use Milky\Services\ServiceFactory;

/**
 * The MIT License (MIT)
 * Copyright 2016 Penoaks Publishing Co. <development@penoaks.org>
 *
 * This Source Code is subject to the terms of the MIT License.
 * If a copy of the license was not distributed with this file,
 * You can obtain one at https://opensource.org/licenses/MIT.
 */
class PermissionManager
{
	/**
	 * Are operator users allowed? TODO CONFIG
	 *
	 * @var bool
	 */
	private $allowOps = true;

	/**
	 * The loaded permission nodes.
	 *
	 * @var array
	 */
	protected $loadedPermissions = [];

	/**
	 * @var Policy[]
	 */
	protected $loadedPolicies = [];

	/**
	 * @var array
	 */
	protected $loadedPoliciesNested = [];

	public static function i()
	{
		return UniversalBuilder::resolveClass( static::class );
	}

	/**
	 * Adds a new policy checker.
	 *
	 * Policies are checked for permissions before they are checked by the general permission backend
	 *
	 * @param Policy $policy
	 */
	public function policy( Policy $policy )
	{
		$this->loadedPolicies[] = $policy;

		// Caches the policy nodes as a nestable tree
		foreach ( $policy->getNodes() as $nodeKey => $nodeValue )
			Arr::set( $this->loadedPoliciesNested, $nodeKey . '.__def', $nodeValue );
	}

	public function checkPolicies( $namespace, $entity )
	{
		$steps = explode( '.', $namespace );
		$result = true;

		$ns = null;
		$next = $steps[0];

		do
		{
			$ns = implode( '.', [$ns, $next] );

			if ( $node = Arr::get( $this->loadedPoliciesNested, $ns . ".__def" ) )
			{
				$result = UniversalBuilder::call( $node, ['entity' => $entity] );
				var_dump( $ns . ".__def" . " --> " . $result );
			}
		}
		while ( $result && $next = next( $steps ) );
	}

	public function has( $permission )
	{

	}

	/**
	 *
	 *
	 * @param string $permission
	 */
	public function parseNode( $permission )
	{
		// Everyone
		if ( is_null( $permission ) || empty( $permission ) || $permission == "-1" || Str::equalsIgnoreCase( $permission, 'everybody' ) || Str::equalsIgnoreCase( $permission, 'everyone' ) )
			$permission = PermissionDefault::EVERYBODY()->getNameSpace();

		// OP Only
		if ( $permission == "0" || Str::equalsIgnoreCase( $permission, 'op' ) || Str::equalsIgnoreCase( $permission, 'root' ) )
			$permission = PermissionDefault::OP()->getNameSpace();

		if ( Str::equalsIgnoreCase( $permission, 'admin' ) )
			$permission = PermissionDefault::ADMIN()->getNameSpace();

		return $permission;
	}

	private function getNode( $getNamespace )
	{

	}
}
