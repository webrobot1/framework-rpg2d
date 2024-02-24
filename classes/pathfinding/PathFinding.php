<?php
// класс для поиска пути Astar
abstract class PathFinding 
{
	private static AStar $_astar;                                        // поиск пути
    private static DomainLogic $_logic;                                    

    // алгоритм поиска пути A*
    final static public function astar(string $from, string $to): array
    {
        // кеширование не актуально nr карта может меняться
        // todo предусмотреть возможность в tettain  блокирвоать клетк (например станвоитя на клетку) и не давать пройти или выталкивать с нее
        if(empty(static::$_astar))
        {
            static::$_logic = new DomainLogic();
            static::$_astar = new AStar(static::$_logic);
        }            

        $start = hrtime(true);
        if($return = static::$_astar->run($from, $to))
        {
            
			Perfomance::set('Sandbox      | расчет поиска пути мс.', (hrtime(true) - $start)/1e+6);
                    
            // удалим точку на которой стоим
            array_shift($return);
        }

        return $return;
    }
}