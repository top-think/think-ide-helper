<?php

namespace think\ide;

class Service extends \think\Service
{
    public function boot()
    {
        $this->commands([ModelCommand::class]);
    }
}
