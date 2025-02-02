<?php

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Type;

/**
 * Class GenerateObjects
 */
class GenerateObjects
{
    private array $spec = [];
    private string $namespace = "HarmSmits\BolComClient\Models";

    private array $types = [
        "boolean" => Type::BOOL,
        "string"  => Type::STRING,
        "number"  => Type::FLOAT,
        "integer" => Type::INT,
        "array"   => Type::ARRAY,
    ];

    /**
     * Load the json specification
     *
     * @param $path
     */
    public function __construct(string $path)
    {
        $this->spec = json_decode(file_get_contents($path), true);
    }

    /**
     * Convert Bol.com API types to native php types
     *
     * @param $type
     *
     * @return mixed
     */
    private function javaTypeFixer($type)
    {
        if (array_key_exists($type, $this->types)) {
            return $this->types[$type];
        } else {
            return $type;
        }
    }

    /**
     * Check if we are dealing with a reference
     *
     * @param array $property
     *
     * @return bool
     */
    private function isReference(array $property)
    {
        return (isset($property['$ref']) || (isset($property['items']) && isset($property['items']['$ref'])));
    }


    /**
     * Get the reference
     *
     * @param array $property
     *
     * @return string
     */
    private function getReference(array $property)
    {
        if (isset($property['$ref'])) {
            return $property['$ref'];
        } else {
            return $property['items']['$ref'];
        }
    }

    /**
     * Get the reference object
     *
     * @param string $reference
     *
     * @return array
     */
    private function getReferenceObject(string $reference)
    {
        $parts = explode("/", $reference);
        $parts[0] === '#' && array_shift($parts);

        $definition = $this->spec;
        foreach ($parts as $part) {
            $definition = $definition[$part];
        }

        return [$parts[array_key_last($parts)], $definition];
    }

    /**
     * Returns the type of a schema
     *
     * @param array $schema
     * @param bool  $strict
     *
     * @return string
     */
    private function getType(array $schema, $strict = true): ?string
    {
        if (isset($schema["format"]) && $schema["format"] === "date-time") {
            return "DateTime";
        } else {
            if ($strict && isset($schema["type"])) {
                return $this->javaTypeFixer($schema["type"]);
            } else if ($this->isReference($schema)) {
                [$name, $definition] = $this->getReferenceObject($this->getReference($schema));
                return $name;
            } else {
                return null;
            }
        }
    }

    /**
     * Parse a property
     *
     * @param       $name
     * @param array $schema
     * @param array $required
     *
     * @return \Nette\PhpGenerator\Property
     */
    private function parseProperty($name, array $schema, array $required)
    {
        $prop = new \Nette\PhpGenerator\Property($name);
        $prop->setProtected();

        $type = $this->getType($schema);

        if (!in_array($name, $required) || $type === Type::ARRAY) {
            if ($type === Type::ARRAY) {
                $prop->setValue([]);
            } else {
                $prop->setValue(isset($schema["default"]) ? $schema["default"] : null);
                $prop->setNullable(!isset($schema["default"]));
            }
            $prop->setInitialized(true);

        } else {
            $prop->setNullable(false);
            $prop->setInitialized(false);
        }

        $prop->setType($type);

        // sets the documentation for each property
        if (isset($schema["description"])) {
            $prop->addComment(\wordwrap($schema["description"], 120, "\n", true));
        }

        if ($this->isReference($schema) && isset($schema["type"])) {
            $prop->addComment(sprintf("@var %s[]", $this->getType($schema, false)));
        } else {
            if (isset($schema["type"])) {
                $prop->addComment(sprintf("@var %s", $this->getType($schema)));
            }
        }

        return $prop;
    }

    /**
     * Prefix the class paths to be absolute
     *
     * @param string $classPath
     *
     * @return string
     */
    private function prefixClassPath(string $classPath)
    {
        return ("\\" . $classPath);
    }

    /**
     * Generates the array export function
     *
     * @param $properties
     *
     * @return string
     */
    private function getArrayExportFunction($properties)
    {
        $code = "return array(\n";
        foreach ($properties as $name => $property) {
            $getter = sprintf('$this->get%s()', ucfirst($name));

            // In case it is an array, we need to convert them back from a pure array
            if (isset($property["type"]) && $property["type"] === Type::ARRAY) {
                $getter = sprintf('$this->_convertPureArray(%s)', $getter);
            }

            $code .= sprintf("\t'%s' => %s,\n", $name, $getter);
        }
        $code .= ");\n";

        return $code;
    }

    private function toHighSnakeCase(string $input)
    {
        return strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    private function sanitizeAmbiguousName($input)
    {
        return preg_replace('/[^a-zA-Z0-9]+/', '_', $input);
    }

    /**
     * Set the setter method
     *
     * @param ClassType                     $class
     * @param                               $name
     * @param array                         $schema
     */
    private function setSetterFunction(ClassType &$class, $name, array $schema)
    {
        $type = $this->getType($schema);
        $body = "";

        if ($this->isReference($schema) && $type === Type::ARRAY) {
            $classname = sprintf("%s\\%s::class", $this->namespace, $this->getType($schema, false));
            $body      .= sprintf("\$this->_checkIfPureArray(%s, %s);\n", "$$name", $this->prefixClassPath($classname));
        }

        if ($type === Type::ARRAY && isset($schema["items"])
            && (isset($schema["minItems"]) || isset($schema["maxItems"]))) {
            $body .= sprintf("\$this->_checkArrayBounds(%s, %s, %s);\n", "$$name", @$schema["minItems"], @$schema["maxItems"]);
        }

        if ($type === "int") {
            if (isset($schema["minimum"]) || isset($schema["maximum"])) {
                $body .= sprintf("\$this->_checkIntegerBounds(%s, %s, %s);\n", "$$name", @$schema["minimum"], @$schema["maximum"]);
            }
        }

        if ($type === "float") {
            if (isset($schema["minimum"]) || isset($schema["maximum"])) {
                $body .= sprintf("\$this->_checkFloatBounds(%s, %s, %s);\n", "$$name", @$schema["minimum"], @$schema["maximum"]);
            }
        }

        if ($type === "DateTime") {
            $body .= '$' . $name . ' = $this->_parseDate($' . $name . ');' . PHP_EOL;
            $type = '';
        }

        if (isset($schema["enum"])) {
            $enums = array_map(function ($item) {
                return sprintf("\t\"%s\"", $item);
            }, $schema["enum"]);

            $body .= sprintf("\$this->_checkEnumBounds(%s, [%s]);\n", "$$name", "\n" . implode(",\n", $enums) . "\n");
        }

        if ($body) {
            $body .= sprintf("\$this->%s = $%s;\n", $name, $name);
            $body .= "return \$this;";

            $method    = $class->addMethod("set" . ucfirst($name));
            $parameter = $method->addParameter($name);
            $parameter->setType($type);
            $method->setBody($body);
            $method->setReturnType('self');
        } else {
            $class->addComment('@method self set' . ucfirst($name) . '(' . $type . ' $' . $name . ')');
        }
    }

    /**
     * Set the getter method
     *
     * @param ClassType                     $class
     * @param                               $name
     * @param array                         $schema
     */
    private function setGetterFunction(ClassType &$class, $name, array $schema)
    {
        $type = $this->getType($schema);
        $class->addComment(sprintf('@method null|' . $type . ' ' . 'get' . ucfirst($name) . '()'));
    }

    /**
     * Set the to array function
     *
     * @param ClassType $class
     * @param array     $properties
     */
    private function setToArrayFunction(ClassType &$class, array $properties)
    {
        $method = $class->addMethod("toArray");
        $method->setBody($this->getArrayExportFunction($properties));
        $method->setReturnType(Type::ARRAY);
    }

    /**
     * Set all enums as constants so that they are actually accessible
     *
     * @param ClassType $class
     * @param string    $property
     * @param array     $enum
     */
    private function setEnums(ClassType &$class, string $property, array $enum)
    {
        $prefix = $this->toHighSnakeCase($property);

        foreach ($enum as $value) {
            $class->addConstant(sprintf("%s_%s", $prefix, $this->sanitizeAmbiguousName($value)), $value);
        }
    }

    /**
     * Generate a class from a definition
     *
     * @param string $name
     * @param array  $definition
     */
    private function generateObject(string $name, array $definition)
    {
        $class = new ClassType($name);
        $class->setFinal(true); // for the love of god, do not extend generated code of all things.
        $class->addExtend($this->prefixClassPath(\HarmSmits\BolComClient\Models\AModel::class));

        foreach ($definition["properties"] as $property => $schema) {
            $class->addMember($this->parseProperty($property, $schema, isset($definition["required"])
                ? $definition["required"] : []));
            $this->setGetterFunction($class, $property, $schema);
            $this->setSetterFunction($class, $property, $schema);

            if (isset($schema["enum"])) {
                $this->setEnums($class, $property, $schema["enum"]);
            }
        }

        $file = <<<PHP
<?php

namespace $this->namespace;

use \DateTime;

$class
PHP;

        file_put_contents(dirname(__DIR__) . "/src/Models/" . $name . ".php", $file);
    }

    /**
     * Generate all the object classes
     */
    private function generateObjects()
    {
        foreach ($this->spec["definitions"] as $name => $definition) {
            $this->generateObject($name, $definition);
        }
    }

    public function generate()
    {
        $this->generateObjects();
    }
}

require(dirname(__DIR__) . "/vendor/autoload.php");

$class = new GenerateObjects(dirname(__DIR__) . "/resources/v5.json");
$class->generate();
