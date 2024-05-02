<?php
enum MathOperationEnum
{
	case Sum;
	case Substract;
	case Multiply;	
	case Divide;	
}

// скорее всего сделать абрастранактным тк например в forward не нужны методы position , а сюда вынести то что точно нужно во всех поситион общее и Forward наследоватьс отсюда а не от position 
abstract class IPosition
{
	use AddMethodsTrait;
	
	public const DELIMETR = ',';
	private array $_coords = array();	
	
	public function __construct(?float $x = null, ?float $y = null, ?float $z = null, protected ?EntityAbstract $object = null)
	{
		if($object)
		{
			if($x!==null || $y!==null || $z!==null)
				throw new Error('Если указано сущность нельзя указывать координаты при создании класса');				
		}
		
		elseif(!is_numeric($x))
			throw new Error('При создании класса '.static::class.' указания существа не указаны ни кординаты x');
		elseif(!is_numeric($y))
			throw new Error('При создании класса '.static::class.' указания существа не указаны ни кординаты y');		
		else
		{
			// что бы была проверка на -0 и round
			self::__set('x', $x);
			self::__set('y', $y);
			self::__set('z', (!empty($z)?$z:0));
		}
	}
	
	public function multiply(Position $vector): Position
    {
		return $this->math($vector, MathOperationEnum::Multiply);
	}		
	
	public function divide(Position $vector): Position
    {
		return $this->math($vector, MathOperationEnum::Divide);
	}		
	
	public function add(Position $vector): Position
    {
		return $this->math($vector, MathOperationEnum::Sum);
	}	
	
	public function subtract(Position $vector): Position
    {
		return $this->math($vector, MathOperationEnum::Substract);
	}	
		
	private function math(Position $vector, MathOperationEnum $operation): Position
	{
		return new Position($this->operation($operation, $this->__get('x'), $vector->__get('x')), $this->operation($operation, $this->__get('y'), $vector->__get('y')), $this->operation($operation, $this->__get('z'), $vector->__get('z')));
	}
	
	private function operation(MathOperationEnum $operation, float $val1, float $val2): float
	{
		$return = 0;
		switch($operation)
		{
			case MathOperationEnum::Sum:
				$return = $val1+$val2;
			break;		
			case MathOperationEnum::Substract:
				$return = $val1-$val2;
			break;			
			case MathOperationEnum::Multiply:
				$return = $val1*$val2;
			break;		
			case MathOperationEnum::Divide:
				if($val2 == 0)
				{
					if($val1!=0)
						throw new Error('Деление на ноль запрещено');
					else
						return 0;
				}
				$return = $val1/$val2;
			break;
		}

		return round($return, POSITION_PRECISION);
	}
	
	// длинна шага в указанном направлении, она же магнитуда https://docs.unity3d.com/ScriptReference/Vector2-magnitude.html
	protected function length(): float
    {
		$position = $this->toArray();
		
		// Внимание ! Для этих вычислений округлять нельзя тк используется для расчетов нормализации векторов
		return sqrt($position['x']*$position['x'] + $position['y']*$position['y'] + $position['z']*$position['z']);
	} 	
		
	final function toArray():array
	{
		// не нужно обращаться напрямую через ->x и ->y: используйте напрямую этот метод для уменьшенеи времени работы PHP на 30% (за счет отсутвия проверки что свойство остутвует и надо вызвать магический метод)
		// тут именно часто бывает обращение к координатам и это время значительно (а из вне в коде игровых механик можно спокойно через ->x и ->y, если там конечно нет гиганских долгих уиклов где это часто запрашивается)	
		return ['x'=>$this->__get('x'), 'y'=>$this->__get('y'), 'z'=>$this->__get('z')];
	}
	
	public function __get(string $key):float
	{
		switch($key)
		{
			case 'x':
			case 'y':
			case 'z':
				if(!empty($this->object))
				{
					$value = $this->object->$key;
					
					// при получении координат существ с другой локации перепсчитываем их в координаты текущей карты
					if($key!='z' && $this->object->map_id != MAP_ID)
					{
						$position = ['x'=>null, 'y'=>null, 'z'=>null];
						$position[$key] = &$value;
						
						// мы храним оригинальные координаты, но при получении их нашими механиками выдаем относительно текущей карты тк у нас единая матрица текущей и смежных локаций
						Map2D::encode2dCoord($position['x'], $position['y'], $this->object->map_id, MAP_ID);
					}
				}
				else
				{
					$value = $this->_coords[$key];
				}

				return $value; 
			break;		
			default:
				throw new Error('нельзя получить поле '. $key);
			break;	
		}
	}
	
	public function __set(string $key, float $value):void
	{
		switch($key)
		{
			case 'x':
			case 'y':
			case 'z':
				if(!empty($this->object))
				{
					throw new Error('Нельзя менять координаты класса '.static::class.' по отдельности ('.$x.'='.$value.'), объект - '.$this->object->key);
				}
				else
				{	
					// Бывает такое в PHP, в объекте (код выше) уже исправлено а напрямую тут исправим
					$value = round($value, POSITION_PRECISION);
					if($value == -0)
						$value = 0;
			
					$this->_coords[$key] = $value;
				}
			break;
			
			default:
				throw new Error('поле '. $key.' нельзя изменить в классе '.static::class.', объект - '.$this->object->key);
			break;	
		}
	}
	
	public function __isset(string $key):bool
	{
		return in_array($key, ['x', 'y', 'z']);
	}

	function __toString():string
	{
		return implode(static::DELIMETR, $this->toArray());
	}	
	
	function __clone():void
	{
		throw new Error('Клонирование объекта позиций запрещено в связи с коллизиями и утчечками памяти которые могут образоваться тк в позициях могут быть ссылки на объекты сущностей');
	}
	
	// если этого не делать будет утечка памяти при удалении существа с карты тк ссылка останется тут
	function __destruct()
	{		
		unset($this->object);			
	}
}