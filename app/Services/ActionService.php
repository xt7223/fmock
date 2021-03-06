<?php
/**
 * @Author huaixiu.zhen@gmail.com
 * http://litblc.com
 * User: huaixiu.zhen
 */
namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use App\Repositories\Eloquent\PostRepository;
use App\Repositories\Eloquent\UserRepository;
use App\Repositories\Eloquent\VideoRepository;
use App\Repositories\Eloquent\AnswerRepository;
use App\Repositories\Eloquent\CommentRepository;
use App\Repositories\Eloquent\UsersFollowRepository;
use App\Repositories\Eloquent\PostsCommentsLikeRepository;

class ActionService extends Service
{
    private $userRepository;

    private $postRepository;

    private $videoRepository;

    private $answerRepository;

    private $commentRepository;

    private $usersFollowRepository;

    private $postsCommentsLikeRepository;

    /**
     * ActionService constructor.
     *
     * @param UserRepository              $userRepository
     * @param PostRepository              $postRepository
     * @param VideoRepository             $videoRepository
     * @param AnswerRepository            $answerRepository
     * @param CommentRepository           $commentRepository
     * @param UsersFollowRepository       $usersFollowRepository
     * @param PostsCommentsLikeRepository $postsCommentsLikeRepository
     */
    public function __construct(
        UserRepository $userRepository,
        PostRepository $postRepository,
        VideoRepository $videoRepository,
        AnswerRepository $answerRepository,
        CommentRepository $commentRepository,
        UsersFollowRepository $usersFollowRepository,
        PostsCommentsLikeRepository $postsCommentsLikeRepository
    ) {
        $this->userRepository = $userRepository;
        $this->postRepository = $postRepository;
        $this->videoRepository = $videoRepository;
        $this->answerRepository = $answerRepository;
        $this->commentRepository = $commentRepository;
        $this->usersFollowRepository = $usersFollowRepository;
        $this->postsCommentsLikeRepository = $postsCommentsLikeRepository;
    }

    /**
     * 获取我关注的所有文章、回答、视频
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $type
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMyFollowed($type)
    {
        $responses = $this->userRepository->getMyFollowed($type);

        if ($responses->count()) {
            foreach ($responses as $post) {

                // 文章列表不需要如下字段
                unset($post->content);
                unset($post->pivot);

                $post->user_info = $this->handleUserInfo($post->user);
                unset($post->user);
            }
        }

        return response()->json(
            ['data' => $responses],
            Response::HTTP_OK
        );
    }

    /**
     * 关注操作 并更新follow_num 表字段
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $type
     * @param $uuid
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function follow($type, $uuid)
    {
        // postRepository or answerRepository、videoRepository
        $repository = $type . 'Repository';

        $post = $this->$repository->findBy('uuid', $uuid);

        if ($post) {
            $follow = $this->userRepository->follow($post->id, $type);

            if (count($follow['attached'])) {
                $post->follow_num += 1;
                $post->save();
            }

            return response()->json(
                ['message' => __('app.follow') . __('app.success')],
                Response::HTTP_OK
            );
        } else {
            return response()->json(
                ['message' => __('app.no_posts')],
                Response::HTTP_NOT_FOUND
            );
        }
    }

    /**
     * 取消关注 并更新follow_num 表字段
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $uuid
     * @param $type
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function unFollow($type, $uuid)
    {
        // postRepository or answerRepository、videoRepository
        $repository = $type . 'Repository';

        $post = $this->$repository->findBy('uuid', $uuid);

        if ($post) {
            if ($this->userRepository->unFollow($post->id, $type)) {
                $post->follow_num > 0 && $post->follow_num -= 1;
                $post->save();
            }

            return response()->json(
                ['message' => __('app.cancel') . __('app.follow') . __('app.success')],
                Response::HTTP_OK
            );
        } else {
            return response()->json(
                ['message' => __('app.no_posts')],
                Response::HTTP_NOT_FOUND
            );
        }
    }

    /**
     * 对 文章/回答/评论 进行 赞、取消赞、踩、取消踩
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $resourceId
     * @param $type
     * @param $resourceType
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function userAction($resourceId, $type, $resourceType)
    {
        $resource = '';
        $field = $type . '_num';

        // 文章和回答都是uuid，评论是id
        if ($resourceType === 'post') {
            $resource = $this->postRepository->findBy('uuid', $resourceId);
        } elseif ($resourceType === 'comment') {
            $resource = $this->commentRepository->find($resourceId);
        } elseif ($resourceType === 'answer') {
            $resource = $this->answerRepository->findBy('uuid', $resourceId);
        }

        if ($resource) {
            $pivot = $this->postsCommentsLikeRepository->hasAction($resource->id, $type, $resourceType);

            if ($pivot) {
                // 取消
                $this->postsCommentsLikeRepository->deleteAction($pivot->id);
                $resource->$field -= 1;
                $resource->save();
                $message = __('app.cancel') . __('app.success');
            } else {
                // 生成
                $this->postsCommentsLikeRepository->makeAction($resource->id, $type, $resourceType);
                $resource->$field += 1;
                $resource->save();
                $message = __('app.success');
            }

            return response()->json(
                ['message' => $message],
                Response::HTTP_OK
            );
        } else {
            return response()->json(
                ['message' => __('app.no_' . $resourceType . 's')],
                Response::HTTP_NOT_FOUND
            );
        }
    }

    /**
     * 查询该 文章/回答/评论 是否存在 赞、踩
     *
     * @Author huaixiu.zhen
     * http://litblc.com
     *
     * @param $resourceId
     * @param $resourceType
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function status($resourceId, $resourceType)
    {
        $resource = '';
        if ($resourceType === 'post') {
            $resource = $this->postRepository->findBy('uuid', $resourceId, ['id']);
        } elseif ($resourceType === 'comment') {
            $resource = $this->commentRepository->find($resourceId, ['id']);
        } elseif ($resourceType === 'answer') {
            $resource = $this->answerRepository->findBy('uuid', $resourceId);
        }

        if ($resource) {
            $like = $this->postsCommentsLikeRepository->hasAction($resource->id, 'like', $resourceType);
            $dislike = $this->postsCommentsLikeRepository->hasAction($resource->id, 'dislike', $resourceType);

            return response()->json(
                ['data' => ['like' => $like ? true : false, 'dislike' => $dislike ? true : false]],
                Response::HTTP_OK
            );
        } else {
            return response()->json(
                ['message' => __('app.no_posts')],
                Response::HTTP_NOT_FOUND
            );
        }
    }

    /**
     * 获取我关注的用户发的 文章、视频、回答
     *
     * author shyZhen <huaixiu.zhen@gmail.com>
     * https://www.litblc.com
     *
     * @param $type
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTrack($type)
    {
        // 先获取我所有关注者的ID
        $userId = Auth::id();
        $myFollowIds = $this->usersFollowRepository->getAllFollowIds($userId);

        $repository = $type . 'Repository';
        $responses = $this->$repository->getResourcesByUserIdArr($myFollowIds);

        if ($responses->count()) {
            foreach ($responses as $response) {

                // 文章列表不需要如下字段
                unset($response->content);
                unset($response->pivot);

                $response->user_info = $this->handleUserInfo($response->user);
                unset($response->user);
            }
        }

        return response()->json(
            ['data' => $responses],
            Response::HTTP_OK
        );
    }
}
