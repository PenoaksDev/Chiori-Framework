<?php

namesapce Penoaks\Contracts\Auth;

interface Guard
{
	/**
	 * Determine if the current user is authenticated.
	 *
	 * @return bool
	 */
	public function check();

	/**
	 * Determine if the current user is a guest.
	 *
	 * @return bool
	 */
	public function guest();

	/**
	 * Get the currently authenticated user.
	 *
	 * @return \Penoaks\Contracts\Auth\Authenticatable|null
	 */
	public function user();

	/**
	 * Get the ID for the currently authenticated user.
	 *
	 * @return int|null
	 */
	public function id();

	/**
	 * Validate a user's credentials.
	 *
	 * @param  array  $credentials
	 * @return bool
	 */
	public function validate(array $credentials = []);

	/**
	 * Set the current user.
	 *
	 * @param  \Penoaks\Contracts\Auth\Authenticatable  $user
	 * @return void
	 */
	public function setUser(Authenticatable $user);
}