<?php

require_once(__DIR__ . '/jre/java_lang.php');
require_once(__DIR__ . '/jre/java_io.php');

\java\lang\System::$out = new \java\io\PrintStream(fopen('php://output', 'wb'));

class JavaInterpreter {
	public $classes = array();
	public $stack = array();

	public function addClass(JavaClass $javaClass) {
		$this->classes[$javaClass->getName()] = $javaClass;
	}
	
	public function callStatic($className, $methodName, $params = array()) {
		/* @var $class JavaClass */
		$class = $this->classes[$className];
		$method = $class->getMethod($methodName);
		$this->interpret($method->code, $params);
	}
	
	protected function stackPush($value) {
		$this->stack[] = $value;
		return $value;
	}
	
	protected function stackPop() {
		return array_pop($this->stack);
	}
	
	protected function stackDump() {
		printf("$$ STACK[%s]\n", @json_encode($this->stack));
	}
	
	protected function stackPopArray($count) {
		if ($count == 0) return array();
		return array_splice($this->stack, -$count);
	}
	
	public function getPhpClassNameFromJavaClassName($javaClassName) {
		$phpClassName = str_replace('/', '\\', $javaClassName);
		return $phpClassName;
	}
	
	protected function newObject(JavaConstantClassReference $classRef) {
		$className = $classRef->getClassName();
		$phpClassName = $this->getPhpClassNameFromJavaClassName($className);
		return new $phpClassName();
	}
	
	protected function getStaticFieldRef(JavaConstantFieldReference $fieldRef) {
		$className = $fieldRef->getClassReference()->getClassName();
		$fieldName = $fieldRef->getNameTypeDescriptor()->getIdentifierNameString();
		$phpClassName = $this->getPhpClassNameFromJavaClassName($className);
		return $phpClassName::$$fieldName;
	}
	
	protected function _callMethod($func, $params) {
		// Static call.
		if (is_string($func[0])) {
			if (isset($this->classes[$func[0]])) {
				return $this->callStatic($func[0], $func[1], $params);
			}
		}
		
		if (!is_callable($func)) {
			//print_r($func);
			if (!is_string($func[0])) {
				$func[0] = 'INSTANCEOF(' . get_class($func[0]) . ')';
			}
			$func_name = implode('::', $func);
			
			throw(new Exception("Can't call '" . $func_name . "'"));
		}
		
		$returnValue = call_user_func_array($func, $params);
		return $returnValue;
	}
	
	protected function callMethodStack(JavaConstantMethodReference $methodRef, $invokeStatic) {
		$nameTypeDescriptor = $methodRef->getNameTypeDescriptor();
		$methodName = $nameTypeDescriptor->getIdentifierNameString();
		/* @var $type JavaTypeMethod */
		$methodType = $nameTypeDescriptor->getTypeDescriptor();
			
		$paramsCount = count($methodType->params);
		$params = $this->stackPopArray($paramsCount);
		if (!$invokeStatic) {
			$object = $this->stackPop();
		}
		
		if ($methodName == '<init>') {
			$methodName = '__java_constructor';
		}
		
		if (!$invokeStatic) {
			$func = array($object, $methodName);
		} else {
			$func = array($this->getPhpClassNameFromJavaClassName($methodRef->getClassReference()->getClassName()), $methodName);
		}

		$returnValue = $this->_callMethod($func, $params);
		
		if (!($methodType->return instanceof JavaTypeIntegralVoid)) {
			$this->stackPush($returnValue);
		}
		//array_slice($this->stack, -$paramsCount);
		//echo "paramsCount: $paramsCount\n";
	}
	
	protected function interpret(JavaCode $code, $params = array()) {
		$locals = array();
		foreach ($params as $k => $param) $locals[$k] = $param;
		
		//$trace = true;
		$trace = false;
		
		$f = string_to_stream($code->code); fseek($f, 0);
		//$javaDisassembler = new JavaDisassembler($code); $javaDisassembler->disasm(); 
		while (!feof($f)) {
			$instruction_offset = ftell($f);
			$op = fread1($f);
			if ($trace) {
				printf("-------------------------------------------------------\n");
				printf("::[%08X] %s(0x%02X)\n", $instruction_offset, JavaOpcodes::getOpcodeName($op), $op);
				$this->stackDump();
			}
			switch ($op) {
				case JavaOpcodes::OP_GETSTATIC:
					$param0 = fread2_be($f);
					/* @var $fieldRef JavaConstantFieldReference */
					$fieldRef = $code->constantPool->get($param0);
					
					$ref = $this->getStaticFieldRef($fieldRef);
					$this->stackPush($ref);
				break;
				case JavaOpcodes::OP_BIPUSH:
					$param0 = fread1_S($f);
					
					$this->stackPush($param0);
				break;
				case JavaOpcodes::OP_SIPUSH:
					$param0 = fread2_be_s($f);
					
					$this->stackPush($param0);
				break;
				case JavaOpcodes::OP_ICONST_0:
				case JavaOpcodes::OP_ICONST_1:
				case JavaOpcodes::OP_ICONST_2:
				case JavaOpcodes::OP_ICONST_3:
				case JavaOpcodes::OP_ICONST_4:
				case JavaOpcodes::OP_ICONST_5:
					$this->stackPush($op - JavaOpcodes::OP_ICONST_0);
				break;
				case JavaOpcodes::OP_LDC:
					$param0 = fread1($f);
					/* @var $constant JavaConstant */
					$constant = $code->constantPool->get($param0);
					
					$this->stackPush($constant->getValue());
				break;
				case JavaOpcodes::OP_ISTORE_0:
				case JavaOpcodes::OP_ISTORE_1:
				case JavaOpcodes::OP_ISTORE_2:
				case JavaOpcodes::OP_ISTORE_3:
					$locals[$op - JavaOpcodes::OP_ISTORE_0] = $this->stackPop();
				break;
				case JavaOpcodes::OP_ASTORE_0:
				case JavaOpcodes::OP_ASTORE_1:
				case JavaOpcodes::OP_ASTORE_2:
				case JavaOpcodes::OP_ASTORE_3:
					$locals[$op - JavaOpcodes::OP_ASTORE_0] = $this->stackPop();
				break;
				// @OTOD. BUG. It is a reference not the value itself!
				case JavaOpcodes::OP_ALOAD_0:
				case JavaOpcodes::OP_ALOAD_1:
				case JavaOpcodes::OP_ALOAD_2:
				case JavaOpcodes::OP_ALOAD_3:
					$this->stackPush($locals[$op - JavaOpcodes::OP_ALOAD_0]);
				break;
				case JavaOpcodes::OP_ILOAD_0:
				case JavaOpcodes::OP_ILOAD_1:
				case JavaOpcodes::OP_ILOAD_2:
				case JavaOpcodes::OP_ILOAD_3:
					$this->stackPush($locals[$op - JavaOpcodes::OP_ILOAD_0]);
				break;
				case JavaOpcodes::OP_INVOKESPECIAL:
				case JavaOpcodes::OP_INVOKEVIRTUAL:
				case JavaOpcodes::OP_INVOKESTATIC:
					$param0 = fread2_be($f);
					/* @var $methodRef JavaConstantMethodReference */
					$methodRef = $code->constantPool->get($param0);

					$this->callMethodStack($methodRef, $invokeStatic = ($op == JavaOpcodes::OP_INVOKESTATIC));
				break;
				case JavaOpcodes::OP_GOTO:
					$relativeAddress = fread2_be_s($f);
					fseek($f, $instruction_offset + $relativeAddress);
				break;
				case JavaOpcodes::OP_IF_ICMPLT:
					$relativeAddress = fread2_be_s($f);
					$valueRight = $this->stackPop();
					$valueLeft  = $this->stackPop();
					if ($valueLeft < $valueRight) {
						fseek($f, $instruction_offset + $relativeAddress);
					}
					//echo "$valueLeft; $valueRight\n";
				break;
				case JavaOpcodes::OP_IFGE:
					$relativeAddress = fread2_be_s($f);
					$valueRight = 0;
					$valueLeft  = $this->stackPop();
					if ($valueLeft >= $valueRight) {
						fseek($f, $instruction_offset + $relativeAddress);
					}
					//echo "$valueLeft; $valueRight\n";
				break;
				case JavaOpcodes::OP_IINC:
					$param0 = fread1($f);
					$param1 = fread1_s($f);
					$locals[$param0] += $param1;
				break;
				case JavaOpcodes::OP_IMUL:
					$valueRight = $this->stackPop();
					$valueLeft  = $this->stackPop();
					$this->stackPush($valueLeft * $valueRight);
				break;
				case JavaOpcodes::OP_I2B:
					$this->stackPush(value_get_byte($this->stackPop()));
				break;
				case JavaOpcodes::OP_NEW:
					$param0 = fread2_be($f);
					/* @var $classRef JavaConstantClassReference */
					$classRef = $code->constantPool->get($param0);
					$this->stackPush($this->newObject($classRef));
				break;
				case JavaOpcodes::OP_ANEWARRAY:
					$classIndex = fread2_be($f);
					
					$array = new ArrayObject();
					$count = $this->stackPop();
					for ($n = 0; $n < $count; $n++) $array[] = null;
					$this->stackPush($array);
				break;
				case JavaOpcodes::OP_NEWARRAY:
					$type = fread1($f);
					
					$array = new ArrayObject();
					$count = $this->stackPop();
					for ($n = 0; $n < $count; $n++) $array[] = null;
					$this->stackPush($array);
				break;
				case JavaOpcodes::OP_ARRAYLENGTH:
					$v = $this->stackPop();
					//echo count($v);
					//$this->stackPush($v);
					$this->stackPush(count($v));
				break;
				case JavaOpcodes::OP_AASTORE:
				case JavaOpcodes::OP_BASTORE:
					$value = $this->stackPop();
					$index = $this->stackPop();
					$array = $this->stackPop();
					$array[$index] = $value;
					if ($trace) {
						echo "VALUE:"; var_dump($value);
						echo "INDEX:"; var_dump($index);
						echo "ARRAY:"; var_dump($array);
					}
					break;
				case JavaOpcodes::OP_BALOAD:
					$index = $this->stackPop();
					$array = $this->stackPop();
					if ($trace) {
						echo "INDEX:"; var_dump($index);
						echo "ARRAY:"; var_dump($array);
					}
					$this->stackPush($array[$index]);
				break;
				case JavaOpcodes::OP_DUP:
					$v = $this->stackPop();
					$this->stackPush($v);
					$this->stackPush($v);
				break;
				case JavaOpcodes::OP_RETURN:
					return;
				break;
				default: throw(new Exception(sprintf("Don't know how to interpret opcode(0x%02X) : %s", $op, JavaOpcodes::getOpcodeName($op))));
			}
		}
	}
}
