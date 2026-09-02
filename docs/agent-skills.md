# agent-skills.json vocabulary

`/agent-skills.json` is a JSON-LD document published by WP Agent Support Layer. It uses schema.org
for the site and publisher description and the terms below, under the prefix
`wpasl:` (`https://github.com/111x-SAS/wp-agent-support-layer/blob/main/docs/agent-skills.md#`),
for the capability list.

Only actions that work **without authentication** are declared. The document is regenerated on the
plugin's schedule and whenever its settings change.

## Document

| Term | Type | Meaning |
| --- | --- | --- |
| `@type` | `WebSite` | The site the capabilities belong to. |
| `name`, `url`, `description`, `inLanguage` | schema.org | Site metadata. |
| `publisher` | `Organization` | Site owner with a technical contact `email`. |
| `manifestVersion` (`wpasl:manifestVersion`) | string | Version of this vocabulary. Currently `1.0`. |
| `generatedAt` (`wpasl:generatedAt`) | xsd:dateTime | When the document was generated (UTC). |
| `contentSignals` (`wpasl:contentSignals`) | object | The site's Content Signals: `search`, `ai-input`, `ai-train`, each `yes` or `no`. |
| `capabilities` (`wpasl:capabilities`) | ordered list | The capabilities, see below. |

## Capability

| Term | Type | Meaning |
| --- | --- | --- |
| `id` (`wpasl:capabilityId`) | string | Stable identifier, e.g. `search-content`, `list-post`, `read-page`, `read-markdown`, `site-index`, `openapi`. |
| `name` | string | Human-readable name. |
| `description` | string | What the capability does and returns. |
| `method` (`wpasl:httpMethod`) | string | HTTP method. Always `GET` for the built-in capabilities. |
| `url` | URL | Absolute endpoint URL, when it has no placeholders. |
| `urlTemplate` (`wpasl:urlTemplate`) | string | Endpoint URL with `{placeholders}` matching path parameters. |
| `authentication` (`wpasl:authentication`) | string | Always `none`. Capabilities that need authentication are never published. |
| `responseType` (`wpasl:responseType`) | media type | `application/json` or `text/markdown`. |
| `parameters` (`wpasl:parameters`) | ordered list | Parameters, see below. |

## Parameter

| Term | Type | Meaning |
| --- | --- | --- |
| `name` | string | Parameter name. |
| `in` (`wpasl:parameterLocation`) | `query` or `path` | Where the parameter goes. |
| `type` (`wpasl:parameterType`) | JSON type | `string`, `integer`, `boolean`, ... |
| `required` (`wpasl:required`) | boolean | Whether the parameter is mandatory. |
| `description` | string | What the parameter means. |

## Extending

Developers can add capabilities with the `wpasl_agent_capabilities` filter. Entries that declare
any `authentication` other than `none`, or that lack a `url` / `urlTemplate`, are dropped.

```php
add_filter( 'wpasl_agent_capabilities', function ( array $capabilities ) {
	$capabilities[] = array(
		'id'          => 'available-slots',
		'name'        => 'Available appointment slots',
		'description' => 'Lists free slots for a given day.',
		'method'      => 'GET',
		'url'         => rest_url( 'booking/v1/slots' ),
		'parameters'  => array(
			array( 'name' => 'date', 'in' => 'query', 'type' => 'string', 'required' => true, 'description' => 'YYYY-MM-DD' ),
		),
	);
	return $capabilities;
} );
```

Companion documents: `/llms.txt` (site index), `/wp-json/wpasl/v1/openapi` (OpenAPI 3.1 for the REST
capabilities) and `/.well-known/api-catalog` (RFC 9727 linkset pointing to both).
