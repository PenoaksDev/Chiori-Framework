<?php namespace Milky\Annotations;

use Milky\Annotations\Annotation\Attribute;
use Milky\Annotations\Annotation\Attributes;
use Milky\Annotations\Annotation\Enum;
use Milky\Annotations\Annotation\Target;
use Milky\Helpers\Str;

final class DocParser
{
	private static $classIdentifiers = [
		DocLexer::T_IDENTIFIER,
		DocLexer::T_TRUE,
		DocLexer::T_FALSE,
		DocLexer::T_NULL
	];

	/**
	 * @var array
	 */
	private static $typeMap = [];

	/**
	 * The lexer.
	 *
	 * @varDocLexer
	 */
	private $lexer;

	/**
	 * Current target context.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Doc parser used to collect annotation target.
	 *
	 * @varDocParser
	 */
	private static $metadataParser;

	/**
	 * Flag to control if the current annotation is nested or not.
	 *
	 * @var boolean
	 */
	private $isNestedAnnotation = false;

	/**
	 * Hashmap containing all use-statements that are to be used when parsing
	 * the given doc block.
	 *
	 * @var array
	 */
	private $imports = [];

	/**
	 * This hashmap is used internally to cache results of class_exists()
	 * look-ups.
	 *
	 * @var array
	 */
	private $classExists = [];

	/**
	 * Whether annotations that have not been imported should be ignored.
	 *
	 * @var boolean
	 */
	private $ignoreNotImportedAnnotations = false;

	/**
	 * An array of default namespaces if operating in simple mode.
	 *
	 * @var array
	 */
	private $namespaces = [];

	/**
	 * A list with annotations that are not causing exceptions when not resolved to an annotation class.
	 *
	 * The names must be the raw names as used in the class, not the fully qualified
	 * class names.
	 *
	 * @var array
	 */
	private $ignoredAnnotationNames = [];

	/**
	 * @var string
	 */
	private $context = '';

	private static $annotationMetadata = [
		'Milky\Annotations\Annotation\Target' => [
			'is_annotation' => true,
			'has_constructor' => true,
			'properties' => [],
			'targets_literal' => 'ANNOTATION_CLASS',
			'targets' => Target::TARGET_CLASS,
			'default_property' => 'value',
			'attribute_types' => [
				'value' => [
					'required' => false,
					'type' => 'array',
					'array_type' => 'string',
					'value' => 'array<string>'
				]
			],
		],
		'Milky\Annotations\Annotation\Attribute' => [
			'is_annotation' => true,
			'has_constructor' => false,
			'targets_literal' => 'ANNOTATION_ANNOTATION',
			'targets' => Target::TARGET_ANNOTATION,
			'default_property' => 'name',
			'properties' => [
				'name' => 'name',
				'type' => 'type',
				'required' => 'required'
			],
			'attribute_types' => [
				'value' => [
					'required' => true,
					'type' => 'string',
					'value' => 'string'
				],
				'type' => [
					'required' => true,
					'type' => 'string',
					'value' => 'string'
				],
				'required' => [
					'required' => false,
					'type' => 'boolean',
					'value' => 'boolean'
				]
			],
		],
		'Milky\Annotations\Annotation\Attributes' => [
			'is_annotation' => true,
			'has_constructor' => false,
			'targets_literal' => 'ANNOTATION_CLASS',
			'targets' => Target::TARGET_CLASS,
			'default_property' => 'value',
			'properties' => [
				'value' => 'value'
			],
			'attribute_types' => [
				'value' => [
					'type' => 'array',
					'required' => true,
					'array_type' => 'Milky\Annotations\Annotation\Attribute',
					'value' => 'array<Milky\Annotations\Annotation\Attribute>'
				]
			],
		],
		'Milky\Annotations\Annotation\Enum' => [
			'is_annotation' => true,
			'has_constructor' => true,
			'targets_literal' => 'ANNOTATION_PROPERTY',
			'targets' => Target::TARGET_PROPERTY,
			'default_property' => 'value',
			'properties' => [
				'value' => 'value'
			],
			'attribute_types' => [
				'value' => [
					'type' => 'array',
					'required' => true,
				],
				'literal' => [
					'type' => 'array',
					'required' => false,
				],
			],
		],
	];

	public function __construct()
	{
		$this->lexer = new DocLexer;
	}

	public function setIgnoredAnnotationNames( array $names )
	{
		$this->ignoredAnnotationNames = $names;
	}

	public function setIgnoreNotImportedAnnotations( $bool )
	{
		$this->ignoreNotImportedAnnotations = (boolean) $bool;
	}

	/**
	 * Sets the default namespaces.
	 *
	 * @param array $namespace
	 *
	 * @return void
	 *
	 * @throws \RuntimeException
	 */
	public function addNamespace( $namespace )
	{
		$this->namespaces[] = $namespace;
	}

	/**
	 * Sets the imports.
	 *
	 * @param array $imports
	 *
	 * @return void
	 *
	 * @throws \RuntimeException
	 */
	public function setImports( array $imports )
	{
		$this->imports = $imports;
	}


	public function setTarget( $target )
	{
		$this->target = $target;
	}


	public function parse( $input, $context = '' )
	{
		$pos = $this->findInitialTokenPosition( $input );
		if ( $pos === null )
			return [];

		$this->context = $context;

		$this->lexer->setInput( trim( substr( $input, $pos ), '* /' ) );
		$this->lexer->moveNext();

		return $this->Annotations();
	}

	/**
	 * Finds the first valid annotation
	 *
	 * @param string $input The docblock string to parse
	 *
	 * @return int|null
	 */
	private function findInitialTokenPosition( $input )
	{
		$pos = 0;

		// search for first valid annotation
		while ( ( $pos = strpos( $input, '@', $pos ) ) !== false )
		{
			// if the @ is preceded by a space or * it is valid
			if ( $pos === 0 || $input[$pos - 1] === ' ' || $input[$pos - 1] === '*' )
				return $pos;

			$pos++;
		}

		return null;
	}


	private function match( $token )
	{
		if ( !$this->lexer->isNextToken( $token ) )
			$this->syntaxError( $this->lexer->getLiteral( $token ) );

		return $this->lexer->moveNext();
	}


	private function matchAny( array $tokens )
	{
		if ( !$this->lexer->isNextTokenAny( $tokens ) )
			$this->syntaxError( implode( ' or ', array_map( [$this->lexer, 'getLiteral'], $tokens ) ) );

		return $this->lexer->moveNext();
	}

	/**
	 * Generates a new syntax error.
	 *
	 * @param string $expected Expected string.
	 * @param array|null $token Optional token.
	 *
	 * @return void
	 *
	 * @throws AnnotationException
	 */
	private function syntaxError( $expected, $token = null )
	{
		if ( $token === null )
			$token = $this->lexer->lookahead;

		$message = sprintf( 'Expected %s, got ', $expected );
		$message .= ( $this->lexer->lookahead === null ) ? 'end of string' : sprintf( "'%s' at position %s", $token['value'], $token['position'] );

		if ( strlen( $this->context ) )
			$message .= ' in ' . $this->context;

		$message .= '.';

		throw AnnotationException::syntaxError( $message );
	}

	/**
	 * Attempts to check if a class exists or not.
	 *
	 * @param string $fqcn
	 *
	 * @return boolean
	 */
	private function classExists( $fqcn )
	{
		if ( isset( $this->classExists[$fqcn] ) )
			return $this->classExists[$fqcn];

		// first check if the class already exists, maybe loaded through another AnnotationReader
		if ( class_exists( $fqcn ) )
			return $this->classExists[$fqcn] = true;

		return false;
	}


	private function collectAnnotationMetadata( $name )
	{
		if ( self::$metadataParser === null )
		{
			self::$metadataParser = new self();

			self::$metadataParser->setIgnoreNotImportedAnnotations( true );
			self::$metadataParser->setIgnoredAnnotationNames( $this->ignoredAnnotationNames );
			self::$metadataParser->setImports( [
				'enum' => 'Milky\Annotations\Annotation\Enum',
				'target' => 'Milky\Annotations\Annotation\Target',
				'attribute' => 'Milky\Annotations\Annotation\Attribute',
				'attributes' => 'Milky\Annotations\Annotation\Attributes'
			] );
		}

		$class = new \ReflectionClass( $name );
		$docComment = $class->getDocComment();

		// Sets default values for annotation metadata
		$metadata = [
			'default_property' => null,
			'has_constructor' => ( null !== $constructor = $class->getConstructor() ) && $constructor->getNumberOfParameters() > 0,
			'properties' => [],
			'property_types' => [],
			'attribute_types' => [],
			'targets_literal' => null,
			'targets' => Target::TARGET_ALL,
			'is_annotation' => false !== strpos( $docComment, '@Annotation' ),
		];

		// verify that the class is really meant to be an annotation
		if ( $metadata['is_annotation'] )
		{
			self::$metadataParser->setTarget( Target::TARGET_CLASS );

			foreach ( self::$metadataParser->parse( $docComment, 'class @' . $name ) as $annotation )
			{
				if ( $annotation instanceof Target )
				{
					$metadata['targets'] = $annotation->targets;
					$metadata['targets_literal'] = $annotation->literal;

					continue;
				}

				if ( $annotation instanceof Attributes )
					foreach ( $annotation->value as $attribute )
						$this->collectAttributeTypeMetadata( $metadata, $attribute );
			}

			// if not has a constructor will inject values into public properties
			if ( false === $metadata['has_constructor'] )
			{
				// collect all public properties
				foreach ( $class->getProperties( \ReflectionProperty::IS_PUBLIC ) as $property )
				{
					$metadata['properties'][$property->name] = $property->name;

					if ( false === ( $propertyComment = $property->getDocComment() ) )
						continue;

					$attribute = new Attribute();

					$attribute->required = ( false !== strpos( $propertyComment, '@Required' ) );
					$attribute->name = $property->name;
					$attribute->type = ( false !== strpos( $propertyComment, '@var' ) && preg_match( '/@var\s+([^\s]+)/', $propertyComment, $matches ) ) ? $matches[1] : 'mixed';

					$this->collectAttributeTypeMetadata( $metadata, $attribute );

					// checks if the property has @Enum
					if ( false !== strpos( $propertyComment, '@Enum' ) )
					{
						$context = 'property ' . $class->name . "::\$" . $property->name;

						self::$metadataParser->setTarget( Target::TARGET_PROPERTY );

						foreach ( self::$metadataParser->parse( $propertyComment, $context ) as $annotation )
						{
							if ( !$annotation instanceof Enum )
								continue;

							$metadata['enum'][$property->name]['value'] = $annotation->value;
							$metadata['enum'][$property->name]['literal'] = ( !empty( $annotation->literal ) ) ? $annotation->literal : $annotation->value;
						}
					}
				}

				// choose the first property as default property
				$metadata['default_property'] = reset( $metadata['properties'] );
			}
		}

		self::$annotationMetadata[$name] = $metadata;
	}


	private function collectAttributeTypeMetadata( &$metadata, Attribute $attribute )
	{
		// handle internal type declaration
		$type = isset( self::$typeMap[$attribute->type] ) ? self::$typeMap[$attribute->type] : $attribute->type;

		// handle the case if the property type is mixed
		if ( 'mixed' === $type )
			return;

		// Evaluate type
		switch ( true )
		{
			// Checks if the property has array<type>
			case ( false !== $pos = strpos( $type, '<' ) ):
				$arrayType = substr( $type, $pos + 1, -1 );
				$type = 'array';

				if ( isset( self::$typeMap[$arrayType] ) )
					$arrayType = self::$typeMap[$arrayType];

				$metadata['attribute_types'][$attribute->name]['array_type'] = $arrayType;
				break;

			// Checks if the property has type[]
			case ( false !== $pos = strrpos( $type, '[' ) ):
				$arrayType = substr( $type, 0, $pos );
				$type = 'array';

				if ( isset( self::$typeMap[$arrayType] ) )
					$arrayType = self::$typeMap[$arrayType];

				$metadata['attribute_types'][$attribute->name]['array_type'] = $arrayType;
				break;
		}

		$metadata['attribute_types'][$attribute->name]['type'] = $type;
		$metadata['attribute_types'][$attribute->name]['value'] = $attribute->type;
		$metadata['attribute_types'][$attribute->name]['required'] = $attribute->required;
	}

	/**
	 * Annotations ::= Annotation {[ "*" ]* [Annotation]}*
	 *
	 * @return array
	 */
	private function Annotations()
	{
		$annotations = [];

		while ( null !== $this->lexer->lookahead )
		{
			if ( DocLexer::T_AT !== $this->lexer->lookahead['type'] )
			{
				$this->lexer->moveNext();
				continue;
			}

			// make sure the @ is preceded by non-catchable pattern
			if ( null !== $this->lexer->token && $this->lexer->lookahead['position'] === $this->lexer->token['position'] + strlen( $this->lexer->token['value'] ) )
			{
				$this->lexer->moveNext();
				continue;
			}

			// make sure the @ is followed by either a namespace separator, or
			// an identifier token
			if ( ( null === $peek = $this->lexer->glimpse() ) || ( DocLexer::T_NAMESPACE_SEPARATOR !== $peek['type'] && !in_array( $peek['type'], self::$classIdentifiers, true ) ) || $peek['position'] !== $this->lexer->lookahead['position'] + 1 )
			{
				$this->lexer->moveNext();
				continue;
			}

			$this->isNestedAnnotation = false;
			if ( false !== $annot = $this->Annotation() )
				$annotations[] = $annot;
		}

		return $annotations;
	}

	/**
	 * Annotation     ::= "@" AnnotationName MethodCall
	 * AnnotationName ::= QualifiedName | SimpleName
	 * QualifiedName  ::= NameSpacePart "\" {NameSpacePart "\"}* SimpleName
	 * NameSpacePart  ::= identifier | null | false | true
	 * SimpleName     ::= identifier | null | false | true
	 *
	 * @return mixed False if it is not a valid annotation.
	 *
	 * @throws AnnotationException
	 */
	private function Annotation()
	{
		$this->match( DocLexer::T_AT );

		// check if we have an annotation
		$name = $this->Identifier();

		// only process names which are not fully qualified, yet
		// fully qualified names must start with a \
		$originalName = $name;

		if ( '\\' !== $name[0] )
		{
			$alias = ( false === $pos = strpos( $name, '\\' ) ) ? $name : substr( $name, 0, $pos );
			$found = true;

			if ( $import = $this->namespaced( $alias ) )
				$name = $import;
			elseif ( $import = $this->imported( $alias ) ) // isset( $this->imports[$loweredAlias = strtolower( $alias )] ) )
				$name = $import;
				// $name = ( false !== $pos ) ? $this->imports[$loweredAlias] . substr( $name, $pos ) : $this->imports[$loweredAlias];
			elseif ( !isset( $this->ignoredAnnotationNames[$name] ) && isset( $this->imports['__NAMESPACE__'] ) && $this->classExists( $this->imports['__NAMESPACE__'] . '\\' . $name ) )
				$name = $this->imports['__NAMESPACE__'] . '\\' . $name;
			elseif ( !isset( $this->ignoredAnnotationNames[$name] ) && $this->classExists( $name ) )
			{
				//
			}
			else
				$found = false;

			if ( !$found )
			{
				if ( $this->ignoreNotImportedAnnotations || isset( $this->ignoredAnnotationNames[$name] ) )
					return false;

				throw AnnotationException::semanticalError( sprintf( 'The annotation "@%s" in %s was never imported. Did you maybe forget to add a "use" statement for this annotation?', $name, $this->context ) );
			}
		}

		if ( !$this->classExists( $name ) )
			throw AnnotationException::semanticalError( sprintf( 'The annotation "@%s" in %s does not exist, or could not be auto-loaded.', $name, $this->context ) );

		// at this point, $name contains the fully qualified class name of the
		// annotation, and it is also guaranteed that this class exists, and
		// that it is loaded


		// collects the metadata annotation only if there is not yet
		if ( !isset( self::$annotationMetadata[$name] ) )
			$this->collectAnnotationMetadata( $name );

		// verify that the class is really meant to be an annotation and not just any ordinary class
		if ( self::$annotationMetadata[$name]['is_annotation'] === false )
		{
			if ( isset( $this->ignoredAnnotationNames[$originalName] ) )
				return false;

			throw AnnotationException::semanticalError( sprintf( 'The class "%s" is not annotated with @Annotation. Are you sure this class can be used as annotation? If so, then you need to add @Annotation to the _class_ doc comment of "%s". If it is indeed no annotation, then you need to add @IgnoreAnnotation("%s") to the _class_ doc comment of %s.', $name, $name, $originalName, $this->context ) );
		}

		//if target is nested annotation
		$target = $this->isNestedAnnotation ? Target::TARGET_ANNOTATION : $this->target;

		// Next will be nested
		$this->isNestedAnnotation = true;

		//if annotation does not support current target
		if ( 0 === ( self::$annotationMetadata[$name]['targets'] & $target ) && $target )
			throw AnnotationException::semanticalError( sprintf( 'Annotation @%s is not allowed to be declared on %s. You may only use this annotation on these code elements: %s.', $originalName, $this->context, self::$annotationMetadata[$name]['targets_literal'] ) );

		$values = $this->MethodCall();

		if ( isset( self::$annotationMetadata[$name]['enum'] ) )
			// checks all declared attributes
			foreach ( self::$annotationMetadata[$name]['enum'] as $property => $enum )
				// checks if the attribute is a valid enumerator
				if ( isset( $values[$property] ) && !in_array( $values[$property], $enum['value'] ) )
					throw AnnotationException::enumeratorError( $property, $name, $this->context, $enum['literal'], $values[$property] );

		// checks all declared attributes
		foreach ( self::$annotationMetadata[$name]['attribute_types'] as $property => $type )
		{
			if ( $property === self::$annotationMetadata[$name]['default_property'] && !isset( $values[$property] ) && isset( $values['value'] ) )
				$property = 'value';

			// handle a not given attribute or null value
			if ( !isset( $values[$property] ) )
			{
				if ( $type['required'] )
					throw AnnotationException::requiredError( $property, $originalName, $this->context, 'a(n) ' . $type['value'] );

				continue;
			}

			if ( $type['type'] === 'array' )
			{
				// handle the case of a single value
				if ( !is_array( $values[$property] ) )
					$values[$property] = [$values[$property]];

				// checks if the attribute has array type declaration, such as "array<string>"
				if ( isset( $type['array_type'] ) )
					foreach ( $values[$property] as $item )
						if ( gettype( $item ) !== $type['array_type'] && !$item instanceof $type['array_type'] )
							throw AnnotationException::attributeTypeError( $property, $originalName, $this->context, 'either a(n) ' . $type['array_type'] . ', or an array of ' . $type['array_type'] . 's', $item );
			}
			elseif ( gettype( $values[$property] ) !== $type['type'] && !$values[$property] instanceof $type['type'] )
				throw AnnotationException::attributeTypeError( $property, $originalName, $this->context, 'a(n) ' . $type['value'], $values[$property] );
		}

		// check if the annotation expects values via the constructor,
		// or directly injected into public properties
		if ( self::$annotationMetadata[$name]['has_constructor'] === true )
			return new $name( $values );

		$instance = new $name();

		foreach ( $values as $property => $value )
		{
			if ( !isset( self::$annotationMetadata[$name]['properties'][$property] ) )
			{
				if ( 'value' !== $property )
					throw AnnotationException::creationError( sprintf( 'The annotation @%s declared on %s does not have a property named "%s". Available properties: %s', $originalName, $this->context, $property, implode( ', ', self::$annotationMetadata[$name]['properties'] ) ) );

				// handle the case if the property has no annotations
				if ( !$property = self::$annotationMetadata[$name]['default_property'] )
					throw AnnotationException::creationError( sprintf( 'The annotation @%s declared on %s does not accept any values, but got %s.', $originalName, $this->context, json_encode( $values ) ) );
			}

			$instance->{$property} = $value;
		}

		return $instance;
	}

	private function imported( $name )
	{
		foreach ( $this->imports as $import )
			if( Str::endsWith( $import, $name ) )
				return $import;

		return false;
	}

	private function namespaced( $name )
	{
		foreach ( $this->namespaces as $namespace )
			if ( $this->classExists( $namespace . '\\' . $name ) )
				return $namespace . '\\' . $name;

		return false;
	}

	/**
	 * MethodCall ::= ["(" [Values] ")"]
	 *
	 * @return array
	 */
	private function MethodCall()
	{
		$values = [];

		if ( !$this->lexer->isNextToken( DocLexer::T_OPEN_PARENTHESIS ) )
			return $values;

		$this->match( DocLexer::T_OPEN_PARENTHESIS );

		if ( !$this->lexer->isNextToken( DocLexer::T_CLOSE_PARENTHESIS ) )
			$values = $this->Values();

		$this->match( DocLexer::T_CLOSE_PARENTHESIS );

		return $values;
	}

	/**
	 * Values ::= Array | Value {"," Value}* [","]
	 *
	 * @return array
	 */
	private function Values()
	{
		$values = [$this->Value()];

		while ( $this->lexer->isNextToken( DocLexer::T_COMMA ) )
		{
			$this->match( DocLexer::T_COMMA );

			if ( $this->lexer->isNextToken( DocLexer::T_CLOSE_PARENTHESIS ) )
				break;

			$token = $this->lexer->lookahead;
			$value = $this->Value();

			if ( !is_object( $value ) && !is_array( $value ) )
				$this->syntaxError( 'Value', $token );

			$values[] = $value;
		}

		foreach ( $values as $k => $value )
		{
			if ( is_object( $value ) && $value instanceof \stdClass )
				$values[$value->name] = $value->value;
			else if ( !isset( $values['value'] ) )
				$values['value'] = $value;
			else
			{
				if ( !is_array( $values['value'] ) )
					$values['value'] = [$values['value']];

				$values['value'][] = $value;
			}

			unset( $values[$k] );
		}

		return $values;
	}

	/**
	 * Constant ::= integer | string | float | boolean
	 *
	 * @return mixed
	 *
	 * @throws AnnotationException
	 */
	private function Constant()
	{
		$identifier = $this->Identifier();

		if ( !defined( $identifier ) && false !== strpos( $identifier, '::' ) && '\\' !== $identifier[0] )
		{
			list( $className, $const ) = explode( '::', $identifier );

			$alias = ( false === $pos = strpos( $className, '\\' ) ) ? $className : substr( $className, 0, $pos );
			$found = false;

			switch ( true )
			{
				case !empty ( $this->namespaces ):
					foreach ( $this->namespaces as $ns )
						if ( class_exists( $ns . '\\' . $className ) || interface_exists( $ns . '\\' . $className ) )
						{
							$className = $ns . '\\' . $className;
							$found = true;
							break;
						}
					break;

				case isset( $this->imports[$loweredAlias = strtolower( $alias )] ):
					$found = true;
					$className = ( false !== $pos ) ? $this->imports[$loweredAlias] . substr( $className, $pos ) : $this->imports[$loweredAlias];
					break;

				default:
					if ( isset( $this->imports['__NAMESPACE__'] ) )
					{
						$ns = $this->imports['__NAMESPACE__'];

						if ( class_exists( $ns . '\\' . $className ) || interface_exists( $ns . '\\' . $className ) )
						{
							$className = $ns . '\\' . $className;
							$found = true;
						}
					}
					break;
			}

			if ( $found )
				$identifier = $className . '::' . $const;
		}

		// checks if identifier ends with ::class, \strlen('::class') === 7
		$classPos = stripos( $identifier, '::class' );
		if ( $classPos === strlen( $identifier ) - 7 )
			return substr( $identifier, 0, $classPos );

		if ( !defined( $identifier ) )
			throw AnnotationException::semanticalErrorConstants( $identifier, $this->context );

		return constant( $identifier );
	}

	private function Identifier()
	{
		// check if we have an annotation
		if ( !$this->lexer->isNextTokenAny( self::$classIdentifiers ) )
			$this->syntaxError( 'namespace separator or identifier' );

		$this->lexer->moveNext();

		$className = $this->lexer->token['value'];

		while ( $this->lexer->lookahead['position'] === ( $this->lexer->token['position'] + strlen( $this->lexer->token['value'] ) ) && $this->lexer->isNextToken( DocLexer::T_NAMESPACE_SEPARATOR ) )
		{
			$this->match( DocLexer::T_NAMESPACE_SEPARATOR );
			$this->matchAny( self::$classIdentifiers );

			$className .= '\\' . $this->lexer->token['value'];
		}

		return $className;
	}

	/**
	 * Value ::= PlainValue | FieldAssignment
	 *
	 * @return mixed
	 */
	private function Value()
	{
		$peek = $this->lexer->glimpse();

		if ( DocLexer::T_EQUALS === $peek['type'] )
			return $this->FieldAssignment();

		return $this->PlainValue();
	}

	/**
	 * PlainValue ::= integer | string | float | boolean | Array | Annotation
	 *
	 * @return mixed
	 */
	private function PlainValue()
	{
		if ( $this->lexer->isNextToken( DocLexer::T_OPEN_CURLY_BRACES ) )
			return $this->Arrayx();

		if ( $this->lexer->isNextToken( DocLexer::T_AT ) )
			return $this->Annotation();

		if ( $this->lexer->isNextToken( DocLexer::T_IDENTIFIER ) )
			return $this->Constant();

		switch ( $this->lexer->lookahead['type'] )
		{
			case DocLexer::T_STRING:
				$this->match( DocLexer::T_STRING );

				return $this->lexer->token['value'];

			case DocLexer::T_INTEGER:
				$this->match( DocLexer::T_INTEGER );

				return (int) $this->lexer->token['value'];

			case DocLexer::T_FLOAT:
				$this->match( DocLexer::T_FLOAT );

				return (float) $this->lexer->token['value'];

			case DocLexer::T_TRUE:
				$this->match( DocLexer::T_TRUE );

				return true;

			case DocLexer::T_FALSE:
				$this->match( DocLexer::T_FALSE );

				return false;

			case DocLexer::T_NULL:
				$this->match( DocLexer::T_NULL );

				return null;

			default:
				$this->syntaxError( 'PlainValue' );
		}

		return null;
	}

	private function FieldAssignment()
	{
		$this->match( DocLexer::T_IDENTIFIER );
		$fieldName = $this->lexer->token['value'];

		$this->match( DocLexer::T_EQUALS );

		$item = new \stdClass();
		$item->name = $fieldName;
		$item->value = $this->PlainValue();

		return $item;
	}

	/**
	 * Array ::= "{" ArrayEntry {"," ArrayEntry}* [","] "}"
	 *
	 * @return array
	 */
	private function Arrayx()
	{
		$array = $values = [];

		$this->match( DocLexer::T_OPEN_CURLY_BRACES );

		// If the array is empty, stop parsing and return.
		if ( $this->lexer->isNextToken( DocLexer::T_CLOSE_CURLY_BRACES ) )
		{
			$this->match( DocLexer::T_CLOSE_CURLY_BRACES );

			return $array;
		}

		$values[] = $this->ArrayEntry();

		while ( $this->lexer->isNextToken( DocLexer::T_COMMA ) )
		{
			$this->match( DocLexer::T_COMMA );

			// optional trailing comma
			if ( $this->lexer->isNextToken( DocLexer::T_CLOSE_CURLY_BRACES ) )
				break;

			$values[] = $this->ArrayEntry();
		}

		$this->match( DocLexer::T_CLOSE_CURLY_BRACES );

		foreach ( $values as $value )
		{
			list ( $key, $val ) = $value;

			if ( $key !== null )
				$array[$key] = $val;
			else
				$array[] = $val;
		}

		return $array;
	}

	/**
	 * ArrayEntry ::= Value | KeyValuePair
	 * KeyValuePair ::= Key ("=" | ":") PlainValue | Constant
	 * Key ::= string | integer | Constant
	 *
	 * @return array
	 */
	private function ArrayEntry()
	{
		$peek = $this->lexer->glimpse();

		if ( DocLexer::T_EQUALS === $peek['type'] || DocLexer::T_COLON === $peek['type'] )
		{
			if ( $this->lexer->isNextToken( DocLexer::T_IDENTIFIER ) )
				$key = $this->Constant();
			else
			{
				$this->matchAny( [DocLexer::T_INTEGER, DocLexer::T_STRING] );
				$key = $this->lexer->token['value'];
			}

			$this->matchAny( [DocLexer::T_EQUALS, DocLexer::T_COLON] );

			return [$key, $this->PlainValue()];
		}

		return [null, $this->Value()];
	}
}
