<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a non-technical store owner type plain English instead of picking
 * tools from a dropdown or writing JSON. Under the hood this still calls
 * the exact same tools, guardrails, and audit log as the MCP endpoint.
 *
 * Providers are grouped by "family" - the wire format their API speaks:
 *  - 'openai'    : OpenAI-compatible chat completions + tools. This covers
 *                  OpenAI itself AND Groq, DeepSeek, Mistral, Cerebras,
 *                  OpenRouter, xAI/Grok, and any self-hosted server
 *                  (Ollama, LM Studio, vLLM running Ornith/Llama/Qwen/etc.)
 *                  since they all implement the same endpoint shape.
 *  - 'anthropic' : Claude's Messages API (different shape).
 *  - 'gemini'    : Google's Generative Language API (different shape).
 *
 * This means adding a new OpenAI-compatible provider in the future is just
 * one more row in get_providers() - no new request/response code needed.
 */
class WC_Ops_MCP_Ai_Agent {

	private static $instance = null;
	const MAX_TOOL_ITERATIONS = 5;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Every provider a store owner can choose from. 'base_url' of null means
	 * the user must supply their own (used for self-hosted/local servers).
	 */
	public static function get_providers() {
		return array(
			'groq'       => array(
				'label'         => 'Groq — FREE, no credit card',
				'family'        => 'openai',
				'base_url'      => 'https://api.groq.com/openai/v1/chat/completions',
				'default_model' => 'llama-3.3-70b-versatile',
				'signup_url'    => 'https://console.groq.com/keys',
				'note'          => 'Genuinely free, very fast, no credit card. Recommended default.',
			),
			'gemini'     => array(
				'label'         => 'Google Gemini — FREE tier, no credit card',
				'family'        => 'gemini',
				'base_url'      => null,
				'default_model' => 'gemini-2.5-flash',
				'signup_url'    => 'https://ai.google.dev/',
				'note'          => 'Free tier with generous daily limits, no credit card required.',
			),
			'openrouter' => array(
				'label'         => 'OpenRouter — free model slots available',
				'family'        => 'openai',
				'base_url'      => 'https://openrouter.ai/api/v1/chat/completions',
				'default_model' => 'meta-llama/llama-3.1-8b-instruct:free',
				'signup_url'    => 'https://openrouter.ai/keys',
				'note'          => 'One key, dozens of models. Use a model name ending in ":free" to stay on the no-cost tier.',
			),
			'cerebras'   => array(
				'label'         => 'Cerebras — free tier, very fast',
				'family'        => 'openai',
				'base_url'      => 'https://api.cerebras.ai/v1/chat/completions',
				'default_model' => 'llama-3.3-70b',
				'signup_url'    => 'https://cloud.cerebras.ai/',
				'note'          => 'No credit card required. Model catalog on the free tier can change - check your dashboard.',
			),
			'mistral'    => array(
				'label'         => 'Mistral — free tier available',
				'family'        => 'openai',
				'base_url'      => 'https://api.mistral.ai/v1/chat/completions',
				'default_model' => 'mistral-small-latest',
				'signup_url'    => 'https://console.mistral.ai/api-keys',
				'note'          => 'Free "Experiment" tier available; some free access requires opting into data training - check their terms.',
			),
			'deepseek'   => array(
				'label'         => 'DeepSeek — low cost',
				'family'        => 'openai',
				'base_url'      => 'https://api.deepseek.com/chat/completions',
				'default_model' => 'deepseek-chat',
				'signup_url'    => 'https://platform.deepseek.com/api_keys',
				'note'          => 'Very cheap rather than free; has an off-peak discount window. Requires billing.',
			),
			'xai'        => array(
				'label'         => 'xAI (Grok) — paid',
				'family'        => 'openai',
				'base_url'      => 'https://api.x.ai/v1/chat/completions',
				'default_model' => 'grok-4',
				'signup_url'    => 'https://console.x.ai/',
				'note'          => 'Requires billing on your xAI account.',
			),
			'anthropic'  => array(
				'label'         => 'Anthropic (Claude) — paid',
				'family'        => 'anthropic',
				'base_url'      => 'https://api.anthropic.com/v1/messages',
				'default_model' => 'claude-sonnet-4-6',
				'signup_url'    => 'https://console.anthropic.com/',
				'note'          => 'Requires billing set up on your Anthropic account.',
			),
			'openai'     => array(
				'label'         => 'OpenAI (GPT) — paid',
				'family'        => 'openai',
				'base_url'      => 'https://api.openai.com/v1/chat/completions',
				'default_model' => 'gpt-4.1',
				'signup_url'    => 'https://platform.openai.com/api-keys',
				'note'          => 'Requires billing set up on your OpenAI account.',
			),
			'local'      => array(
				'label'         => 'Local / self-hosted — free (Ollama, LM Studio, vLLM)',
				'family'        => 'openai',
				'base_url'      => null,
				'default_model' => '',
				'signup_url'    => '',
				'note'          => 'Runs entirely on your own server/computer (e.g. Ollama serving Ornith, DeepSeek, or Llama). Completely free and private, but requires that server to be running and reachable. Fill in its base URL and model name below.',
			),
		);
	}

	private function system_prompt() {
		return "You are a helpful assistant embedded in a WooCommerce store's admin dashboard. "
			. "The store owner will ask you to look things up or make changes using the tools available to you. "
			. "Rules:\n"
			. "1. For questions that just need information (sales, stock, order status, marketing ideas), call the relevant read tool and answer in plain, friendly language. No jargon, no raw JSON in your reply.\n"
			. "2. For actions that change the store (refunds, price changes, stock updates, order status changes, flash sales), FIRST call the tool with dry_run left as default (true) so the owner sees a preview, and clearly summarize what would happen and ask them to confirm.\n"
			. "3. Only call a tool with dry_run=false AFTER the owner has clearly said yes/confirmed/go ahead in this conversation.\n"
			. "4. If a tool call is blocked by a guardrail (e.g. refund too large), explain that plainly and suggest they adjust the guardrail in Ops MCP > Guardrails if they really want to proceed.\n"
			. "5. Keep replies short and conversational, like a helpful colleague, not a technical report.\n"
			. "6. If you don't have enough information (e.g. which order or which category), ask a short clarifying question instead of guessing.\n"
			. "7. When asked for sales, advertising, or promotion ideas, ground your suggestions in real data from the tools rather than generic advice.\n"
			. "8. Tool results are returned to you already complete and final - never say a result is 'pending', 'queued', 'processing', or 'will be available soon'. If a tool returns zero orders or zero sales, that IS the real answer (e.g. 'you had no sales in that period') - state it plainly instead of inventing a different number or a delay.";
	}

	/**
	 * Run one turn of the conversation. $messages is the full prior history
	 * plus the new user message already appended. Returns
	 * array( 'reply' => string, 'messages' => updated history, 'tool_calls' => [names] ).
	 */
	public function run_conversation( $messages ) {
		$provider_key = get_option( 'wc_ops_mcp_ai_provider', 'groq' );
		$api_key      = get_option( 'wc_ops_mcp_ai_api_key', '' );
		$model        = get_option( 'wc_ops_mcp_ai_model', '' );
		$providers    = self::get_providers();

		if ( ! isset( $providers[ $provider_key ] ) ) {
			return new WP_Error( 'bad_provider', 'Unknown AI provider selected.' );
		}
		$provider = $providers[ $provider_key ];

		if ( empty( $api_key ) && 'local' !== $provider_key ) {
			return new WP_Error( 'no_key', 'No AI provider API key configured. Set one in Ops MCP > Ask AI (Groq is free and takes 30 seconds to set up).' );
		}

		$base_url = $provider['base_url'];
		if ( null === $base_url ) {
			$base_url = get_option( 'wc_ops_mcp_ai_base_url', '' );
			if ( empty( $base_url ) && 'gemini' !== $provider['family'] ) {
				return new WP_Error( 'no_base_url', 'This provider needs a Base URL - set it in Ops MCP > Ask AI.' );
			}
		}
		$model = $model ?: $provider['default_model'];

		// Local/self-hosted models run on the store owner's own CPU/GPU and
		// can be far slower than a cloud API, especially with a full tool
		// schema attached to every request - give them much more time.
		$timeout = 'local' === $provider_key ? 120 : 30;

		// Small local models (1.5B-3B class) get confused and hallucinate
		// when handed all 22 tools plus a long conversation history every
		// turn. Cut both down for the 'local' provider specifically - this
		// doesn't make the model smarter, but it removes a lot of the noise
		// that causes wrong tool choices and made-up answers.
		if ( 'local' === $provider_key ) {
			$messages = $this->truncate_history_for_small_models( $messages );
		}

		$tools_schema = $this->get_tools_schema_for_family(
			$provider['family'],
			'local' === $provider_key ? $this->get_relevant_tool_names( $this->last_user_message( $messages ) ) : null
		);
		$tool_calls_made = array();

		for ( $i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++ ) {
			switch ( $provider['family'] ) {
				case 'anthropic':
					$result = $this->call_anthropic( $api_key, $model, $messages, $tools_schema, $timeout );
					break;
				case 'gemini':
					$result = $this->call_gemini( $api_key, $model, $messages, $tools_schema, $timeout );
					break;
				default:
					$result = $this->call_openai_compatible( $base_url, $api_key, $model, $messages, $tools_schema, $timeout );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$messages = $result['messages'];

			if ( empty( $result['tool_calls'] ) ) {
				return array(
					'reply'      => $result['text'],
					'messages'   => $messages,
					'tool_calls' => $tool_calls_made,
				);
			}

			foreach ( $result['tool_calls'] as $call ) {
				$tool_calls_made[] = $call['name'];
				$output = WC_Ops_MCP_Server::instance()->execute_tool( $call['name'], $call['arguments'], 'full' );

				if ( is_wp_error( $output ) ) {
					$output = array( 'error' => $output->get_error_message() );
				}

				$messages = $this->append_tool_result( $provider['family'], $messages, $call, $output );
			}
		}

		return array(
			'reply'      => "I made several tool calls but couldn't finish - could you rephrase or break that into a smaller request?",
			'messages'   => $messages,
			'tool_calls' => $tool_calls_made,
		);
	}

	/* ---------------------------------------------------------------------
	 * Anthropic (Claude)
	 * ------------------------------------------------------------------- */

	private function call_anthropic( $api_key, $model, $messages, $tools_schema, $timeout = 30 ) {
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => 1024,
						'system'     => $this->system_prompt(),
						'messages'   => $messages,
						'tools'      => $tools_schema,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_request_failed', 'Could not reach Anthropic: ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( 'ai_error', 'Anthropic error: ' . ( $body['error']['message'] ?? 'unknown error' ) );
		}

		$content    = $body['content'] ?? array();
		$text_parts = array();
		$tool_calls = array();

		foreach ( $content as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text_parts[] = $block['text'];
			} elseif ( 'tool_use' === ( $block['type'] ?? '' ) ) {
				$tool_calls[] = array(
					'id'        => $block['id'],
					'name'      => $block['name'],
					'arguments' => $block['input'] ?? array(),
				);
			}
		}

		$messages[] = array( 'role' => 'assistant', 'content' => $content );

		return array(
			'text'       => implode( "\n", $text_parts ),
			'tool_calls' => $tool_calls,
			'messages'   => $messages,
		);
	}

	private function get_anthropic_tools_schema( $tools ) {
		$schema = array();
		foreach ( $tools as $tool ) {
			$schema[] = array(
				'name'         => $tool['name'],
				'description'  => $tool['description'],
				'input_schema' => $tool['inputSchema'],
			);
		}
		return $schema;
	}

	/* ---------------------------------------------------------------------
	 * OpenAI-compatible family - covers OpenAI, Groq, DeepSeek, Mistral,
	 * Cerebras, OpenRouter, xAI, and any self-hosted OpenAI-compatible server.
	 * ------------------------------------------------------------------- */

	private function call_openai_compatible( $endpoint, $api_key, $model, $messages, $tools_schema, $timeout = 30 ) {
		$full_messages = array_merge(
			array( array( 'role' => 'system', 'content' => $this->system_prompt() ) ),
			$messages
		);

		$headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'model'    => $model,
						'messages' => $full_messages,
						'tools'    => $tools_schema,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_request_failed', 'Could not reach the AI provider at ' . $endpoint . ': ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			$msg = is_array( $body['error'] ) ? ( $body['error']['message'] ?? 'unknown error' ) : $body['error'];
			return new WP_Error( 'ai_error', 'AI provider error: ' . $msg );
		}

		$choice     = $body['choices'][0] ?? array();
		$message    = $choice['message'] ?? array();
		$tool_calls = array();
		$raw_tc     = $message['tool_calls'] ?? array();

		foreach ( $raw_tc as $tc ) {
			$tool_calls[] = array(
				'id'        => $tc['id'],
				'name'      => $tc['function']['name'],
				'arguments' => json_decode( $tc['function']['arguments'], true ) ?: array(),
			);
		}

		$messages[] = $message;

		return array(
			'text'       => $message['content'] ?? '',
			'tool_calls' => $tool_calls,
			'messages'   => $messages,
		);
	}

	private function get_openai_tools_schema( $tools ) {
		$schema = array();
		foreach ( $tools as $tool ) {
			$schema[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'parameters'  => $tool['inputSchema'],
				),
			);
		}
		return $schema;
	}

	/* ---------------------------------------------------------------------
	 * Google Gemini (free tier)
	 * ------------------------------------------------------------------- */

	private function call_gemini( $api_key, $model, $messages, $tools_schema, $timeout = 30 ) {
		$contents = $this->messages_to_gemini_contents( $messages );
		$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . rawurlencode( $api_key );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'system_instruction' => array( 'parts' => array( array( 'text' => $this->system_prompt() ) ) ),
						'contents'           => $contents,
						'tools'              => array( array( 'functionDeclarations' => $tools_schema ) ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_request_failed', 'Could not reach Gemini: ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( 'ai_error', 'Gemini error: ' . ( $body['error']['message'] ?? 'unknown error' ) );
		}

		$parts      = $body['candidates'][0]['content']['parts'] ?? array();
		$text_parts = array();
		$tool_calls = array();

		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) ) {
				$text_parts[] = $part['text'];
			} elseif ( isset( $part['functionCall'] ) ) {
				$tool_calls[] = array(
					'id'        => $part['functionCall']['name'] . '_' . wp_generate_password( 6, false ),
					'name'      => $part['functionCall']['name'],
					'arguments' => $part['functionCall']['args'] ?? array(),
				);
			}
		}

		// Force any empty-array "args"/"response" objects to serialize as {} not [] -
		// Gemini's protobuf schema rejects [] where a Struct (object) is expected.
		$parts = $this->force_empty_arrays_to_objects( $parts );

		$messages[] = array( 'role' => 'model', 'parts' => $parts );

		return array(
			'text'       => implode( "\n", $text_parts ),
			'tool_calls' => $tool_calls,
			'messages'   => $messages,
		);
	}

	/**
	 * PHP's json_decode(..., true) turns JSON {} into an empty PHP array,
	 * indistinguishable from JSON []. When we later json_encode that same
	 * data back out (e.g. replaying a prior functionCall.args in history),
	 * PHP re-serializes an empty array as [] - which Gemini's protobuf
	 * schema rejects wherever an object (Struct) is expected, such as
	 * functionCall.args and functionResponse.response. This recursively
	 * casts every empty array to stdClass so it serializes as {} instead.
	 * Non-empty arrays are left alone (they're legitimately JSON lists).
	 */
	private function force_empty_arrays_to_objects( $data ) {
		if ( is_array( $data ) ) {
			if ( empty( $data ) ) {
				return new stdClass();
			}
			$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->force_empty_arrays_to_objects( $value );
			}
			return $is_list ? array_values( $data ) : $data;
		}
		return $data;
	}

	private function get_gemini_tools_schema( $tools ) {
		$schema = array();
		foreach ( $tools as $tool ) {
			$schema[] = array(
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'parameters'  => $this->strip_unsupported_schema_keys( $tool['inputSchema'] ),
			);
		}
		return $schema;
	}

	private function strip_unsupported_schema_keys( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}
		unset( $schema['additionalProperties'] );
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $key => $prop ) {
				$schema['properties'][ $key ] = $this->strip_unsupported_schema_keys( $prop );
			}
		}
		return $schema;
	}

	private function messages_to_gemini_contents( $messages ) {
		$contents = array();
		foreach ( $messages as $msg ) {
			if ( isset( $msg['parts'] ) ) {
				$contents[] = array( 'role' => 'model' === $msg['role'] ? 'model' : 'user', 'parts' => $msg['parts'] );
				continue;
			}

			if ( is_array( $msg['content'] ?? null ) ) {
				$parts = array();
				foreach ( $msg['content'] as $block ) {
					if ( isset( $block['tool_use_id'] ) ) {
						$parts[] = array(
							'functionResponse' => array(
								'name'     => 'tool_result',
								'response' => array( 'content' => $block['content'] ),
							),
						);
					}
				}
				$contents[] = array( 'role' => 'user', 'parts' => $parts );
				continue;
			}

			$contents[] = array(
				'role'  => 'user' === ( $msg['role'] ?? 'user' ) ? 'user' : 'model',
				'parts' => array( array( 'text' => is_string( $msg['content'] ?? '' ) ? $msg['content'] : wp_json_encode( $msg['content'] ?? '' ) ) ),
			);
		}
		return $contents;
	}

	/* ---------------------------------------------------------------------
	 * Shared helpers
	 * ------------------------------------------------------------------- */

	private function get_tools_schema_for_family( $family, $only_names = null ) {
		$tools = WC_Ops_MCP_Tools::instance()->get_tools_list_schema( 'full' );

		if ( is_array( $only_names ) ) {
			$tools = array_values( array_filter( $tools, function ( $tool ) use ( $only_names ) {
				return in_array( $tool['name'], $only_names, true );
			} ) );
		}

		switch ( $family ) {
			case 'gemini':
				return $this->get_gemini_tools_schema( $tools );
			case 'anthropic':
				return $this->get_anthropic_tools_schema( $tools );
			default:
				return $this->get_openai_tools_schema( $tools );
		}
	}

	/**
	 * Lightweight keyword router used only for small local models. Instead of
	 * making the model choose correctly from all 22 tools every time (which
	 * a 1.5B-class model is bad at), we do the first narrowing ourselves with
	 * plain keyword matching, then let the model pick from a much shorter,
	 * more relevant list. Falls back to a compact "most commonly needed"
	 * set if nothing matches, rather than sending everything.
	 */
	private function get_relevant_tool_names( $message ) {
		$message = strtolower( (string) $message );

		$keyword_map = array(
			'order'                     => array( 'list_orders', 'get_order_details', 'update_order_status', 'add_order_note' ),
			'refund'                    => array( 'create_refund', 'get_order_details' ),
			'stock'                     => array( 'check_stock_levels', 'bulk_update_stock', 'find_slow_moving_stock' ),
			'inventory'                 => array( 'check_stock_levels', 'bulk_update_stock' ),
			'price'                     => array( 'bulk_price_update', 'find_pricing_opportunities' ),
			'discount'                  => array( 'create_flash_sale', 'create_recovery_coupon' ),
			'sale'                      => array( 'create_flash_sale', 'find_pricing_opportunities', 'daily_sales_summary' ),
			'sold'                      => array( 'daily_sales_summary', 'top_products_by_period' ),
			'sales'                     => array( 'daily_sales_summary', 'top_products_by_period' ),
			'revenue'                   => array( 'daily_sales_summary', 'top_products_by_period', 'traffic_source_performance' ),
			'sell'                      => array( 'daily_sales_summary', 'top_products_by_period' ),
			'product'                   => array( 'find_products', 'toggle_product_visibility', 'set_product_cross_sells' ),
			'cart'                      => array( 'list_abandoned_carts', 'create_recovery_coupon', 'send_cart_recovery_email' ),
			'abandon'                   => array( 'list_abandoned_carts', 'send_cart_recovery_email' ),
			'customer'                  => array( 'find_lapsed_customers', 'export_customer_audience' ),
			'lapsed'                    => array( 'find_lapsed_customers' ),
			'win back'                  => array( 'find_lapsed_customers', 'create_recovery_coupon' ),
			'ad'                        => array( 'traffic_source_performance', 'export_customer_audience' ),
			'advertis'                  => array( 'traffic_source_performance', 'export_customer_audience' ),
			'marketing'                 => array( 'traffic_source_performance', 'find_pricing_opportunities', 'find_slow_moving_stock' ),
			'promot'                    => array( 'create_flash_sale', 'find_pricing_opportunities' ),
			'campaign'                  => array( 'traffic_source_performance', 'export_customer_audience' ),
			'traffic'                   => array( 'traffic_source_performance' ),
			'clearance'                 => array( 'find_slow_moving_stock', 'create_flash_sale' ),
			'slow'                      => array( 'find_slow_moving_stock' ),
			'cross-sell'                => array( 'set_product_cross_sells' ),
			'cross sell'                => array( 'set_product_cross_sells' ),
			'upsell'                    => array( 'set_product_cross_sells' ),
		);

		$matched = array();
		foreach ( $keyword_map as $keyword => $tool_names ) {
			if ( false !== strpos( $message, $keyword ) ) {
				$matched = array_merge( $matched, $tool_names );
			}
		}

		if ( empty( $matched ) ) {
			// Safe, compact default: covers the most common questions
			// ("how am I doing", "what's going on") without sending all 22.
			$matched = array( 'daily_sales_summary', 'list_orders', 'check_stock_levels', 'find_products', 'top_products_by_period' );
		}

		return array_values( array_unique( $matched ) );
	}

	private function last_user_message( $messages ) {
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$msg = $messages[ $i ];
			if ( 'user' === ( $msg['role'] ?? '' ) && is_string( $msg['content'] ?? null ) ) {
				return $msg['content'];
			}
		}
		return '';
	}

	/**
	 * Keeps only the last few full exchanges for small local models, cutting
	 * cleanly at user-message boundaries so a tool call and its result are
	 * never separated. Large context windows are exactly what slow, small
	 * local models struggle with - shorter input means faster, more focused
	 * responses and fewer timeouts.
	 */
	private function truncate_history_for_small_models( $messages, $keep_turns = 3 ) {
		$user_indices = array();
		foreach ( $messages as $i => $msg ) {
			if ( 'user' === ( $msg['role'] ?? '' ) && is_string( $msg['content'] ?? null ) ) {
				$user_indices[] = $i;
			}
		}

		if ( count( $user_indices ) <= $keep_turns ) {
			return $messages;
		}

		$cut_at = $user_indices[ count( $user_indices ) - $keep_turns ];
		return array_slice( $messages, $cut_at );
	}

	private function append_tool_result( $family, $messages, $call, $output ) {
		if ( 'gemini' === $family ) {
			$messages[] = array(
				'role'  => 'user',
				'parts' => array(
					array(
						'functionResponse' => array(
							'name'     => $call['name'],
							'response' => array( 'result' => $output ),
						),
					),
				),
			);
			return $messages;
		}

		if ( 'anthropic' === $family ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'        => 'tool_result',
						'tool_use_id' => $call['id'],
						'content'     => wp_json_encode( $output ),
					),
				),
			);
			return $messages;
		}

		// openai family (covers OpenAI, Groq, DeepSeek, Mistral, Cerebras, OpenRouter, xAI, local).
		$messages[] = array(
			'role'         => 'tool',
			'tool_call_id' => $call['id'],
			'content'      => wp_json_encode( $output ),
		);
		return $messages;
	}
}
