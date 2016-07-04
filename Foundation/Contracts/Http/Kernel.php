<?php

namespace Foundation\Contracts\Http;

interface Kernel
{
	/**
	 * Handle an incoming HTTP request.
	 *
	 * @param  \Symfony\Component\HttpFoundation\Request  $request
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function handle($request);

	/**
	 * Perform any final actions for the request lifecycle.
	 *
	 * @param  \Symfony\Component\HttpFoundation\Request  $request
	 * @param  \Symfony\Component\HttpFoundation\Response  $response
	 * @return void
	 */
	public function terminate($request, $response);

	/**
	 * Get the framework application instance.
	 *
	 * @return \Foundation\Framework
	 */
	public function getApplication();
}
