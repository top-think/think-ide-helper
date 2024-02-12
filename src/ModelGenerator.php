<?php

namespace think\ide;

use Exception;
use InvalidArgumentException;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use phpDocumentor\Reflection\DocBlock\Serializer as DocBlockSerializer;
use phpDocumentor\Reflection\DocBlock\StandardTagFactory;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\FqsenResolver;
use phpDocumentor\Reflection\TypeResolver;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Self_;
use phpDocumentor\Reflection\Types\Static_;
use phpDocumentor\Reflection\Types\This;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use think\App;
use think\console\Output;
use think\db\Query;
use think\helper\Arr;
use think\helper\Str;
use think\Model;
use think\model\Collection;
use think\model\Relation;
use think\model\relation\BelongsTo;
use think\model\relation\BelongsToMany;
use think\model\relation\HasMany;
use think\model\relation\HasManyThrough;
use think\model\relation\HasOne;
use think\model\relation\HasOneThrough;
use think\model\relation\MorphMany;
use think\model\relation\MorphOne;
use think\model\relation\MorphTo;
use think\model\relation\MorphToMany;
use Throwable;

class ModelGenerator
{

    protected $app;

    protected $class;

    /** @var ReflectionClass */
    protected $reflection;

    /** @var Model */
    protected $model;

    /** @var Output */
    protected $output;

    protected $properties = [];

    protected $methods = [];

    protected $overwrite = false;

    protected $reset = false;

    public function __construct(App $app, Output $output, $class, $reset, $overwrite)
    {
        $this->app       = $app;
        $this->output    = $output;
        $this->class     = $class;
        $this->reset     = $reset;
        $this->overwrite = $overwrite;
    }

    public function getReflection()
    {
        return $this->reflection;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function addProperty($name, $type = null, $read = null, $write = null, $comment = '')
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name]            = [];
            $this->properties[$name]['type']    = 'mixed';
            $this->properties[$name]['read']    = false;
            $this->properties[$name]['write']   = false;
            $this->properties[$name]['comment'] = (string) $comment;
        }
        if (null !== $type) {
            $this->properties[$name]['type'] = $type;
        }
        if (null !== $read) {
            $this->properties[$name]['read'] = $read;
        }
        if (null !== $write) {
            $this->properties[$name]['write'] = $write;
        }
    }

    public function addMethod($name, $return = 'mixed', $arguments = [], $static = true)
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);
        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name]              = [];
            $this->methods[$name]['static']    = $static ? 'static' : '';
            $this->methods[$name]['arguments'] = $arguments;
            $this->methods[$name]['return']    = $return;
        }
    }

    /**
     * 生成注释
     */
    public function generate()
    {
        $this->reflection = new ReflectionClass($this->class);

        if (!$this->reflection->isSubclassOf(Model::class)) {
            return;
        }

        if ($this->output->getVerbosity() >= Output::VERBOSITY_VERBOSE) {
            $this->output->comment("Loading model '{$this->class}'");
        }

        if (!$this->reflection->isInstantiable()) {
            // 忽略接口和抽象类
            return;
        }

        $this->model = new $this->class;

        $this->getPropertiesFromTable();
        $this->getPropertiesFromMethods();

        //触发事件
        $this->app->event->trigger($this);

        $this->createPhpDocs();
    }

    /**
     * 从数据库读取字段信息
     */
    protected function getPropertiesFromTable()
    {
        $properties = $this->reflection->getDefaultProperties();

        $dateFormat = empty($properties['dateFormat']) ? $this->app->config->get('database.datetime_format') : $properties['dateFormat'];
        try {
            $query = $this->model->db();
            if ($query instanceof Query) {
                $fields = $query->getFields();
            }
        } catch (Exception $e) {
            $this->output->warning($e->getMessage());
        }

        if (!empty($fields)) {
            foreach ($fields as $name => $field) {
                if (in_array($name, (array) $properties['disuse'])) {
                    continue;
                }

                if (in_array($name, [$properties['createTime'], $properties['updateTime']])) {
                    if (false !== strpos($dateFormat, '\\')) {
                        $type = $dateFormat;
                    } else {
                        $type = 'string';
                    }
                } elseif (!empty($properties['type'][$name])) {

                    $type = $properties['type'][$name];

                    if (is_array($type)) {
                        [$type, $param] = $type;
                    } elseif (strpos($type, ':')) {
                        [$type, $param] = explode(':', $type, 2);
                    }

                    switch ($type) {
                        case 'timestamp':
                        case 'datetime':
                            $format = !empty($param) ? $param : $dateFormat;

                            if (false !== strpos($format, '\\')) {
                                $type = $format;
                            } else {
                                $type = 'string';
                            }
                            break;
                        case 'json':
                            $type = 'array';
                            break;
                        case 'serialize':
                            $type = 'mixed';
                            break;
                    }
                } else {
                    if (!preg_match('/^([\w]+)(\(([\d]+)*(,([\d]+))*\))*(.+)*$/', $field['type'], $matches)) {
                        continue;
                    }
                    $limit     = null;
                    $precision = null;
                    $type      = $matches[1];
                    if (count($matches) > 2) {
                        $limit = $matches[3] ? (int) $matches[3] : null;
                    }

                    if ($type === 'tinyint' && $limit === 1) {
                        $type = 'boolean';
                    }

                    switch ($type) {
                        case 'varchar':
                        case 'char':
                        case 'tinytext':
                        case 'mediumtext':
                        case 'longtext':
                        case 'text':
                        case 'timestamp':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                        case 'set':
                        case 'enum':
                            $type = 'string';
                            break;
                        case 'tinyint':
                        case 'smallint':
                        case 'mediumint':
                        case 'int':
                        case 'bigint':
                            $type = 'integer';
                            break;
                        case 'decimal':
                        case 'float':
                            $type = 'float';
                            break;
                        case 'boolean':
                            $type = 'boolean';
                            break;
                        default:
                            $type = 'mixed';
                            break;
                    }
                }
                $comment = $field['comment'] ?? null;
                $this->addProperty($name, $type, true, true, $comment);
            }
        }
    }

    /**
     * 返回方法是否是获取器
     * @param string $name
     * @return bool
     */
    protected function isGetterMethod(string $name): bool
    {
        return Str::startsWith($name, 'get') && Str::endsWith($name, 'Attr') && 'getAttr' !== $name;
    }

    /**
     * 是否是修改器
     * @param string $name
     * @return bool
     */
    protected function isSetterMethod(string $name): bool
    {
        return Str::startsWith($name, 'set') && Str::endsWith($name, 'Attr') && 'setAttr' !== $name;
    }

    /**
     * 是否是关联关系方法
     * @param ReflectionMethod $method
     * @return bool
     */
    protected function isRelationMethod(ReflectionMethod $method): bool
    {
        if (!$method->isPublic() || $method->isAbstract() || $method->isStatic() || $method->getNumberOfParameters() !== 0) {
            return false;
        }

        $type = $this->getReturnTypeFromReflection($method);

        if (strpos($type, '\\') === false) {
            $type = $this->getReturnTypeFromDocBlock($method);
        }

        if (is_null($type) || strpos($type, '\\') === false) {
            goto spl;
        }

        $type = ltrim(ltrim($type, '\\'), '/');

        $relationClasses = [
            HasOne::class,
            BelongsTo::class,
            MorphOne::class,
            HasOneThrough::class,
            HasMany::class,
            HasManyThrough::class,
            BelongsToMany::class,
            MorphTo::class,
            MorphMany::class,
            MorphToMany::class,
        ];

        if (in_array($type, $relationClasses)) {
            return true;
        }

        spl:

        $relationMethods = [
            'hasOne',
            'belongsTo',
            'hasMany',
            'hasManyThrough',
            'hasOneThrough',
            'belongsToMany',
            'morphOne',
            'morphMany',
            'morphTo',
            'morphToMany',
        ];

        $file = new \SplFileObject($method->getFileName());
        $file->seek($method->getStartLine() - 1);
        $code = '';
        while ($file->key() < $method->getEndLine()) {
            $code .= trim($file->current());
            $file->next();
        }

        $contains = false;

        foreach ($relationMethods as $relationMethod) {
            if (strpos($code, '$this->' . $relationMethod . '(') !== false) {
                $contains = true;
                break;
            }
        }

        if (!$contains) {
            return false;
        }

        try {
            return $method->invoke($this->model) instanceof Relation;
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    /**
     * 自动生成获取器和修改器以及关联对象的属性信息
     */
    protected function getPropertiesFromMethods()
    {
        $methods = $this->reflection->getMethods();

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() !== $this->reflection->getName()) {
                continue;
            }

            $methodName = $method->getName();

            if ($this->isGetterMethod($methodName)) {
                //获取器
                $name = Str::snake(substr($methodName, 3, -4));

                if (!empty($name)) {
                    $type = $this->hasReturnTypeFromDocBlock($method) ? $this->getReturnTypeFromDocBlock($method) : $this->getReturnTypeFromReflection($method);

                    $this->addProperty($name, $type, true, null);
                }
            } elseif ($this->isSetterMethod($methodName)) {
                //修改器
                $name = Str::snake(substr($methodName, 3, -4));
                if (!empty($name)) {
                    $this->addProperty($name, null, null, true);
                }
            } elseif (Str::startsWith($methodName, 'scope')) {
                //查询范围
                $name = Str::camel(substr($methodName, 5));

                if (!empty($name)) {
                    $args = $this->getParameters($method);
                    array_shift($args);
                    $this->addMethod($name, Query::class, $args);
                }
            } elseif ($this->isRelationMethod($method)) {
                //关联对象
                try {
                    $return = $method->invoke($this->model);

                    $name = Str::snake($methodName);

                    if ($return instanceof HasOne || $return instanceof BelongsTo || $return instanceof MorphOne || $return instanceof HasOneThrough) {
                        $this->addProperty($name, get_class($return->getModel()) . '|null', true, null);
                    }

                    if ($return instanceof HasMany || $return instanceof HasManyThrough || $return instanceof BelongsToMany || $return instanceof MorphToMany) {
                        $relationModel = get_class($return->getModel());

                        $refection = new ReflectionClass($relationModel);

                        $resultSetType = $refection->getProperty('resultSetType')->getValue($refection->newInstance()) ?: Collection::class;

                        $this->addProperty($name, $resultSetType . '<int, ' . $relationModel . '>|' . $relationModel . '[]', true, null);
                    }

                    if ($return instanceof MorphTo || $return instanceof MorphMany) {
                        $this->addProperty($name, "mixed", true, null);
                    }
                } catch (Exception $e) {
                } catch (Throwable $e) {
                }
            }
        }
    }

    /**
     * 生成注释
     */
    protected function createPhpDocs()
    {
        $classname   = $this->reflection->getShortName();
        $originalDoc = $this->reflection->getDocComment();
        $context     = (new ContextFactory())->createFromReflector($this->reflection);
        $summary     = "Class {$this->class}";

        $properties = [];
        $methods    = [];
        $tags       = [];

        try {
            //读取文件注释
            $phpdoc = DocBlockFactory::createInstance()->create($this->reflection, $context);

            $summary    = $phpdoc->getSummary();
            $properties = [];
            $methods    = [];
            $tags       = $phpdoc->getTags();
            foreach ($tags as $key => $tag) {
                if ($tag instanceof DocBlock\Tags\Property || $tag instanceof DocBlock\Tags\PropertyRead || $tag instanceof DocBlock\Tags\PropertyWrite) {
                    if (($this->overwrite && array_key_exists($tag->getVariableName(), $this->properties)) || $this->reset) {
                        //覆盖原来的
                        unset($tags[$key]);
                    } else {
                        $properties[] = $tag->getVariableName();
                    }
                } elseif ($tag instanceof DocBlock\Tags\Method) {
                    if (($this->overwrite && array_key_exists($tag->getMethodName(), $this->methods)) || $this->reset) {
                        //覆盖原来的
                        unset($tags[$key]);
                    } else {
                        $methods[] = $tag->getMethodName();
                    }
                }
            }
        } catch (InvalidArgumentException $e) {

        }

        $fqsenResolver      = new FqsenResolver();
        $tagFactory         = new StandardTagFactory($fqsenResolver);
        $descriptionFactory = new DescriptionFactory($tagFactory);

        $tagFactory->addService($descriptionFactory);
        $tagFactory->addService(new TypeResolver($fqsenResolver));

        foreach ($this->properties as $name => $property) {
            if (in_array($name, $properties)) {
                continue;
            }

            if ($property['read'] && $property['write']) {
                $attr = 'property';
            } elseif ($property['write']) {
                $attr = 'property-write';
            } else {
                $attr = 'property-read';
            }

            //TODO 属性转驼峰

            $tagLine = trim("@{$attr} {$property['type']} \${$name} {$property['comment']}");

            $tags[] = $tagFactory->create($tagLine);
        }

        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }

            $arguments = implode(', ', $method['arguments']);

            $tags[] = $tagFactory->create("@method {$method['static']} {$method['return']} {$name}({$arguments})");
        }

        $tags = $this->sortTags($tags);

        $phpdoc = new DocBlock($summary, null, $tags, $context);

        $serializer = new DocBlockSerializer();

        $docComment = $serializer->getDocComment($phpdoc);

        $filename = $this->reflection->getFileName();

        $contents = file_get_contents($filename);
        if ($originalDoc) {
            $contents = str_replace($originalDoc, $docComment, $contents);
        } else {
            $needle  = "class {$classname}";
            $replace = "{$docComment}" . PHP_EOL . "class {$classname}";
            $pos     = strpos($contents, $needle);
            if (false !== $pos) {
                $contents = substr_replace($contents, $replace, $pos, strlen($needle));
            }
        }
        if (file_put_contents($filename, $contents)) {
            $this->output->info('Written new phpDocBlock to ' . $filename);
        }
    }

    protected function sortTags($tags)
    {
        $innerTags = ['', 'method', 'property-write', 'property-read', 'property'];

        return Arr::sort($tags, function (DocBlock\Tag $tag1, DocBlock\Tag $tag2) use ($innerTags) {
            $name1  = $tag1->getName();
            $name2  = $tag2->getName();
            $index1 = array_search($name1, $innerTags);
            $index2 = array_search($name2, $innerTags);

            if ($index1 == $index2) {
                return strcmp($tag1->render(), $tag2->render());
            }

            if ($index1 > 0 || $index2 > 0) {
                return $index2 - $index1;
            }
        });
    }

    /**
     * @param ReflectionMethod $method
     * @return array
     */
    protected function getParameters($method)
    {
        $params            = [];
        $paramsWithDefault = [];
        /** @var ReflectionParameter $param */
        foreach ($method->getParameters() as $param) {
            $paramType = $param->getType();

            $paramStr = (!is_null($paramType) ? $paramType->getName() : 'mixed') . ' $' . $param->getName();
            $params[] = $paramStr;
            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                $default = $param->getDefaultValue();
                if (is_bool($default)) {
                    $default = $default ? 'true' : 'false';
                } elseif (is_array($default)) {
                    $default = 'array()';
                } elseif (is_null($default)) {
                    $default = 'null';
                } elseif (is_string($default)) {
                    $default = "'" . trim($default) . "'";
                }
                $paramStr .= " = $default";
            }
            $paramsWithDefault[] = $paramStr;
        }
        return $paramsWithDefault;
    }

    /**
     * phpdoc 中是否有 return
     * @param ReflectionMethod $reflection
     * @return false
     */
    protected function hasReturnTypeFromDocBlock(ReflectionMethod $reflection): bool
    {
        try {
            $context = (new ContextFactory())->createFromReflector($reflection->getDeclaringClass());
            $phpdoc  = DocBlockFactory::createInstance()->create($reflection, $context);

            return $phpdoc->hasTag('return');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 从docblock里获取返回类型
     * @param ReflectionMethod $reflection
     * @return string|null
     */
    protected function getReturnTypeFromDocBlock(ReflectionMethod $reflection): ?string
    {
        $type = null;
        try {
            $context = (new ContextFactory())->createFromReflector($reflection->getDeclaringClass());
            $phpdoc  = DocBlockFactory::createInstance()->create($reflection, $context);
            if ($phpdoc->hasTag('return')) {
                /** @var DocBlock\Tags\Return_ $returnTag */
                $returnTag = $phpdoc->getTagsByName('return')[0];
                $type      = $returnTag->getType();
                if ($type instanceof This || $type instanceof Static_ || $type instanceof Self_) {
                    $type = $reflection->getDeclaringClass()->getName();
                }
            }
        } catch (InvalidArgumentException $e) {

        }
        return is_null($type) ? null : (string) $type;
    }

    /**
     * 从反射里获取返回类型
     * @param ReflectionMethod $reflection
     * @return string
     */
    protected function getReturnTypeFromReflection(ReflectionMethod $reflection): string
    {
        try {
            return $reflection->getReturnType() ? $reflection->getReturnType()->getName() : 'mixed';
        } catch (Throwable $e) {
            return 'mixed';
        }
    }
}
